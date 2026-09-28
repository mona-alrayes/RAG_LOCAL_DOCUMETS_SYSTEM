import json
import logging
import os
import re
from contextvars import ContextVar, Token
from datetime import UTC, datetime
from typing import Any

_correlation_id: ContextVar[str | None] = ContextVar(
    "correlation_id",
    default=None,
)


def get_correlation_id() -> str | None:
    return _correlation_id.get()


def set_correlation_id(correlation_id: str) -> Token[str | None]:
    return _correlation_id.set(correlation_id)


def reset_correlation_id(token: Token[str | None]) -> None:
    _correlation_id.reset(token)


def _redact_credentials(text: str) -> str:
    # Preserve diagnostic prose, HTTP status, paths, timings and model telemetry.
    text = re.sub(r"(?i)\b(Bearer|Basic)\s+[^\s,;\"']+", r"\1 [REDACTED]", text)
    text = re.sub(
        r"(?i)(\b(?:api[_-]?key|password|passwd|access_token|refresh_token|"
        r"client_secret|authorization|cookie|set-cookie)\b[\"']?\s*[:=]\s*)"
        r"(?:\"[^\"\n]*\"|'[^'\n]*'|[^\s&,;]+)",
        r"\1[REDACTED]",
        text,
    )
    return re.sub(r"(https?://)[^\s/@]+:[^\s/@]+@", r"\1[REDACTED]@", text)


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload: dict[str, Any] = {
            "timestamp": datetime.now(UTC).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": _redact_credentials(record.getMessage()),
            "correlation_id": get_correlation_id(),
        }

        if record.exc_info and record.exc_info[0] is not None:
            payload["exception"] = record.exc_info[0].__name__
            payload["traceback"] = _redact_credentials(
                self.formatException(record.exc_info)
            )

        return json.dumps(payload, ensure_ascii=False)


class PrettyFormatter(logging.Formatter):
    _RESET = "\x1b[0m"
    _LEVEL_COLORS = {
        logging.DEBUG: "\x1b[36m",
        logging.INFO: "\x1b[32m",
        logging.WARNING: "\x1b[33m",
        logging.ERROR: "\x1b[31m",
        logging.CRITICAL: "\x1b[1;31m",
    }

    def __init__(self, *, use_colors: bool = False) -> None:
        super().__init__()
        self.use_colors = use_colors

    def format(self, record: logging.LogRecord) -> str:
        timestamp = datetime.fromtimestamp(record.created).astimezone().strftime(
            "%H:%M:%S"
        )
        level = f"{record.levelname:<8}"
        logger_name = f"{record.name:<24}"
        message = _redact_credentials(record.getMessage())
        correlation_id = get_correlation_id()
        correlation = (
            f"  correlation_id={correlation_id}" if correlation_id else ""
        )

        if self.use_colors:
            color = self._LEVEL_COLORS.get(record.levelno, "")
            level = f"{color}{level}{self._RESET}"

        rendered = (
            f"{timestamp}  {level}  {logger_name}  {message}{correlation}"
        )

        if record.exc_info and record.exc_info[0] is not None:
            traceback = _redact_credentials(self.formatException(record.exc_info))
            indented_traceback = "\n".join(
                f"           {line}" for line in traceback.splitlines()
            )
            rendered = f"{rendered}\n{indented_traceback}"

        if self.use_colors:
            rendered = f"{rendered}{self._RESET}"

        return rendered


def configure_logging(
    log_format: str | None = None,
    level: int = logging.INFO,
) -> None:
    handler = logging.StreamHandler()
    selected_format = (
        log_format or os.getenv("LOG_FORMAT", "pretty")
    ).strip().lower()
    if selected_format == "json":
        formatter: logging.Formatter = JsonFormatter()
    else:
        stream_supports_colors = bool(
            hasattr(handler.stream, "isatty") and handler.stream.isatty()
        )
        formatter = PrettyFormatter(
            use_colors=stream_supports_colors and "NO_COLOR" not in os.environ
        )
    handler.setFormatter(formatter)

    root_logger = logging.getLogger()
    root_logger.handlers.clear()
    root_logger.addHandler(handler)
    root_logger.setLevel(level)
    # Uvicorn installs stderr/access handlers before importing the application.
    for name in ("uvicorn", "uvicorn.error", "uvicorn.access"):
        logger = logging.getLogger(name)
        logger.handlers.clear()
        logger.propagate = True
