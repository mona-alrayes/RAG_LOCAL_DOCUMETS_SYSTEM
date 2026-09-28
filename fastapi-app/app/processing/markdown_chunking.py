"""تقطيع Markdown ضمن الصفحة مع حفظ السياق ودون استدعاء موديل."""

import re
from itertools import pairwise

from llama_index.core.node_parser import MarkdownNodeParser, SentenceSplitter
from llama_index.core.schema import Document
from llama_index.core.utils import get_tokenizer

from app.parsing.normalized import NormalizedDocument
from app.processing.chunks import NormalizedChunk

CHUNKING_VERSION = "markdown-sections-scoped-overlap-v3"
_LIST_ITEM = re.compile(r"^\s*(?:[-+*]|\d+[.)])\s+")
_TABLE_RULE = re.compile(r"^\s*\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\s*$")
_FENCE_LINE = re.compile(r"^ {0,3}(`{3,}|~{3,})(.*)$")


def _is_fence_close(match: re.Match[str] | None, fence: str) -> bool:
    return bool(
        match
        and match.group(1)[0] == fence[0]
        and len(match.group(1)) >= len(fence)
        and not match.group(2).strip()
    )


class MarkdownChunker:
    def __init__(self, chunk_size: int, chunk_overlap: int) -> None:
        if chunk_size < 8:
            raise ValueError("chunk_size must be at least 8")
        self._size = chunk_size
        self._overlap = chunk_overlap
        self._tokenize = get_tokenizer()
        separator_tokens = len(self._tokenize("\n\n"))
        self._content_size = max(
            8,
            chunk_size - chunk_overlap - separator_tokens,
        )
        self._fallback = SentenceSplitter(
            chunk_size=self._content_size,
            chunk_overlap=0,
        )
        self._markdown = MarkdownNodeParser.from_defaults()

    def _fits(self, text: str) -> bool:
        return len(self._tokenize(text)) <= self._content_size

    def _bounded(self, text: str, prefix: str = "", suffix: str = "") -> list[str]:
        # نحجز مساحة للعنوان/رأس الجدول قبل تقسيم المحتوى، حتى لا نتجاوز الحد.
        if self._fits(prefix + text + suffix):
            return [prefix + text + suffix]
        budget = self._content_size - len(self._tokenize(prefix + suffix)) - 4
        if budget < 8:
            # العنوان نفسه قد يكون ضخماً؛ نحفظ النص بدلاً من إسقاطه أو الدوران بلا نهاية.
            return self._fallback.split_text(prefix + text + suffix)
        splitter = SentenceSplitter(
            chunk_size=budget,
            chunk_overlap=0,
        )
        output = []
        for part in splitter.split_text(text):
            candidate = prefix + part + suffix
            output.extend(
                [candidate]
                if self._fits(candidate)
                else self._fallback.split_text(candidate)
            )
        return output

    @staticmethod
    def _blocks(text: str) -> list[str]:
        # الفراغ يفصل الفقرات، لكنه لا يفصل كتلة كود أو عناصر قائمة متتابعة.
        blocks: list[str] = []
        current: list[str] = []
        fence: str | None = None
        for line in text.splitlines():
            marker = _FENCE_LINE.match(line)
            if marker and fence is None:
                if current:
                    blocks.append("\n".join(current).strip())
                    current = []
                fence = marker.group(1)
            elif fence and _is_fence_close(marker, fence):
                fence = None
                current.append(line)
                blocks.append("\n".join(current).strip())
                current = []
                continue
            elif fence is None and current:
                # بعض الملفات لا تضع سطراً فارغاً قبل القائمة أو الجدول.
                starts_list = _LIST_ITEM.match(line) and not _LIST_ITEM.match(
                    current[0]
                )
                starts_table = line.lstrip().startswith("|") and not current[
                    0
                ].lstrip().startswith("|")
                if starts_list or starts_table:
                    blocks.append("\n".join(current).strip())
                    current = []
            if not line.strip() and fence is None:
                if current:
                    blocks.append("\n".join(current).strip())
                    current = []
            else:
                current.append(line)
        if current:
            blocks.append("\n".join(current).strip())
        merged: list[str] = []
        for block in blocks:
            if merged and _LIST_ITEM.match(block) and _LIST_ITEM.match(merged[-1]):
                merged[-1] += "\n\n" + block
            else:
                merged.append(block)
        return merged

    @staticmethod
    def _protect_fenced_headings(text: str) -> tuple[str, str]:
        """Mask headings inside any Markdown fence while LlamaIndex parses it."""
        sentinel = "\ue000"
        while sentinel in text:
            sentinel += "\ue001"

        output: list[str] = []
        fence: str | None = None
        for line in text.splitlines(keepends=True):
            marker = _FENCE_LINE.match(line.rstrip("\r\n"))
            if fence is None:
                if marker:
                    fence = marker.group(1)
            else:
                closes_fence = _is_fence_close(marker, fence)
                if re.match(r"^#{1,6}\s+", line):
                    line = sentinel + line
                if closes_fence:
                    fence = None
            if line.lstrip().startswith("```"):
                # نعطّل متتبع LlamaIndex المبسّط ونعتمد حالة fence أعلاه؛
                # فهو يعتبر حتى الأسطر ذات أربع مسافات أو delimiter أقصر حدوداً.
                line = sentinel + line
            output.append(line)
        return "".join(output), sentinel

    def _split_block(self, block: str, prefix: str, lead: str) -> list[str]:
        lines = block.splitlines()
        if len(lines) >= 2 and _TABLE_RULE.match(lines[1]):
            header = "\n".join(lines[:2]) + "\n"
            if len(self._tokenize(prefix + header)) < self._size // 2:
                # نكرر أسماء الأعمدة مع كل مجموعة صفوف حتى تبقى الأرقام مفهومة.
                rows: list[str] = []
                buffer = ""
                for row in lines[2:]:
                    candidate = buffer + row + "\n"
                    if self._fits(prefix + header + candidate):
                        buffer = candidate
                    else:
                        if buffer:
                            rows.append(prefix + header + buffer.rstrip())
                            buffer = ""
                        if self._fits(prefix + header + row):
                            buffer = row + "\n"
                        else:
                            rows.extend(self._bounded(row, prefix + header))
                if buffer:
                    rows.append(prefix + header + buffer.rstrip())
                return rows or self._bounded(block, prefix)
        opening = _FENCE_LINE.match(lines[0]) if lines else None
        if opening:
            marker = opening.group(1)
            closing = _FENCE_LINE.match(lines[-1]) if len(lines) > 1 else None
            if _is_fence_close(closing, marker):
                return self._bounded(
                    "\n".join(lines[1:-1]),
                    prefix + lines[0] + "\n",
                    "\n" + lines[-1],
                )
        if _LIST_ITEM.match(block):
            # تمهيد القائمة قد يحمل شرطاً يخص كل عناصرها؛ نكرره إن اتسع.
            context = prefix
            if lead and len(self._tokenize(prefix + lead)) < self._size // 2:
                context += lead + "\n\n"
            items = re.split(r"\n(?=\s*(?:[-+*]|\d+[.)])\s+)", block)
            return self._pack(items, context)
        return self._bounded(block, prefix)

    def _pack(self, blocks: list[str], prefix: str) -> list[str]:
        output: list[str] = []
        buffer = ""
        for block in blocks:
            candidate = "\n\n".join(filter(None, [buffer, block]))
            if self._fits(prefix + candidate):
                buffer = candidate
            else:
                if buffer:
                    output.append(prefix + buffer)
                    buffer = ""
                if self._fits(prefix + block):
                    buffer = block
                else:
                    output.extend(self._bounded(block, prefix))
        if buffer:
            output.append(prefix + buffer)
        return output

    def _overlap_suffix(self, text: str, budget: int) -> str:
        if budget <= 0 or not text.strip():
            return ""

        best = ""
        starts = [match.start() for match in re.finditer(r"\S+", text)]
        for start in reversed(starts):
            candidate = text[start:].strip()
            if len(self._tokenize(candidate)) > budget:
                break
            best = candidate
        return best

    def _apply_overlap(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[NormalizedChunk]:
        if self._overlap <= 0 or len(chunks) < 2:
            return chunks

        output = [chunks[0]]
        for previous, current in pairwise(chunks):
            separator = "\n\n"
            available = min(
                self._overlap,
                self._size
                - len(self._tokenize(current.text))
                - len(self._tokenize(separator)),
            )
            overlap = self._overlap_suffix(previous.text, available)
            text = separator.join(filter(None, [overlap, current.text]))
            output.append(
                NormalizedChunk(
                    text=text,
                    page=current.page,
                    section=current.section,
                )
            )
        return output

    def chunk(self, documents: list[NormalizedDocument]) -> list[NormalizedChunk]:
        chunks: list[NormalizedChunk] = []
        for document in documents:
            if not document.text.strip():
                continue
            document_chunks: list[NormalizedChunk] = []
            # نبقي الصفحات مستقلة: عقد المصدر الحالي يحمل رقم صفحة واحداً فقط.
            # النسخة المثبتة من LlamaIndex لا تحمي عناوين ~~~ أو الأسوار
            # المتداخلة، لذلك نخفي عناوين الكود عنها ثم نعيد النص كما كان.
            protected_text, sentinel = self._protect_fenced_headings(document.text)
            nodes = self._markdown.get_nodes_from_documents(
                [Document(text=protected_text)]
            )
            for node in nodes:
                text = node.text.replace(sentinel, "").strip()
                first, _, body = text.partition("\n")
                heading = first if re.match(r"^#{1,6}\s+", first) else ""
                parent = node.metadata.get("header_path", "/").strip("/")
                section = (
                    " / ".join(filter(None, [parent, heading.lstrip("# ")]))
                    or document.section
                )
                prefix = (heading + "\n\n") if heading else ""
                content = body.strip() if heading else text
                if len(self._tokenize(text)) <= self._size:
                    # لا نحجز مساحة overlap ما لم تحتج العقدة فعلياً إلى التقسيم.
                    parts = [text]
                elif not content:
                    parts = self._bounded(text)
                else:
                    parts = []
                    pending: list[str] = []
                    previous = ""
                    for block in self._blocks(content):
                        # إذا فصل حد الحجم القائمة عن تمهيدها، ننقل التمهيد مع القائمة.
                        detached_list = (
                            bool(_LIST_ITEM.match(block))
                            and bool(pending)
                            and not self._fits(prefix + "\n\n".join([*pending, block]))
                        )
                        if self._fits(prefix + block) and not detached_list:
                            pending.append(block)
                        else:
                            parts.extend(self._pack(pending, prefix))
                            pending = []
                            parts.extend(self._split_block(block, prefix, previous))
                        previous = block
                    parts.extend(self._pack(pending, prefix))
                node_chunks = [
                    NormalizedChunk(
                        text=part,
                        page=document.page,
                        section=section[:255] if section else None,
                    )
                    for part in parts
                    if part.strip()
                ]
                document_chunks.extend(self._apply_overlap(node_chunks))
            chunks.extend(document_chunks)
        return chunks
