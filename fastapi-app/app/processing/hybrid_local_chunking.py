from app.processing.markdown_chunking import MarkdownChunker


class HybridLocalChunker(MarkdownChunker):
    # نفس التقطيع في المسارين حتى تكون المقارنة مستقلة عن مزوّد التضمين.
    pass
