"""Local-only token budgeting. No model weights or prompt text leave this process."""

from functools import lru_cache

from app.core.exceptions import ApplicationException
from app.services.prompt import BuiltPrompt


@lru_cache(maxsize=1)
def _tokenizer(path: str):
    try:
        from tokenizers import Tokenizer

        return Tokenizer.from_file(path)
    except Exception:  # noqa: BLE001 - the Rust tokenizer raises plain Exception for invalid files.
        raise ApplicationException(
            code="local_tokenizer_unavailable",
            message="The configured local tokenizer could not be loaded.",
        ) from None


def prompt_tokens(prompt: BuiltPrompt, tokenizer_path: str | None = None) -> int:
    texts = (prompt.system_instructions, prompt.user_content)
    if tokenizer_path:
        tokenizer = _tokenizer(tokenizer_path)
        count = sum(
            len(tokenizer.encode(text, add_special_tokens=False).ids) for text in texts
        )
    else:
        # Conservative byte upper bound when no model-matched tokenizer is configured.
        count = sum(len(text.encode("utf-8")) for text in texts)
    # Reserve framing/system/user/assistant markers (text-only chat).
    return count + 256
