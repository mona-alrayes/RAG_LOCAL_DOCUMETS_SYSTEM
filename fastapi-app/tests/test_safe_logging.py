import io
import json
import logging
from types import SimpleNamespace

from app.core.logging import JsonFormatter, PrettyFormatter


def render(message, args=(), exc_info=None):
    record = logging.LogRecord(
        "app.test", logging.ERROR, __file__, 1, message, args, exc_info
    )
    return json.loads(JsonFormatter().format(record))


def render_pretty(message, args=(), exc_info=None):
    record = logging.LogRecord(
        "app.test", logging.INFO, __file__, 1, message, args, exc_info
    )
    return PrettyFormatter(use_colors=False).format(record)


def test_pretty_formatter_renders_a_human_readable_terminal_line():
    from app.core.logging import reset_correlation_id, set_correlation_id

    correlation = "8d51015f-4592-47e4-837f-2a43c76781ff"
    token = set_correlation_id(correlation)
    try:
        rendered = render_pretty(
            'HTTP Request: POST http://127.0.0.1:11434/api/chat "HTTP/1.1 200 OK"'
        )
    finally:
        reset_correlation_id(token)

    assert not rendered.startswith("{")
    assert "INFO" in rendered
    assert "app.test" in rendered
    assert "POST http://127.0.0.1:11434/api/chat" in rendered
    assert f"correlation_id={correlation}" in rendered


def test_pretty_formatter_masks_credentials():
    rendered = render_pretty(
        "HTTP 401 api_key=private123 Authorization: Bearer private456"
    )

    assert "private123" not in rendered
    assert "private456" not in rendered
    assert "[REDACTED]" in rendered


def test_pretty_formatter_colors_log_level_when_enabled():
    record = logging.LogRecord(
        "app.test", logging.WARNING, __file__, 1, "Slow request", (), None
    )

    rendered = PrettyFormatter(use_colors=True).format(record)

    assert "\x1b[33mWARNING" in rendered
    assert rendered.endswith("\x1b[0m")


def test_configure_logging_uses_pretty_format_by_default(monkeypatch):
    from app.core.logging import configure_logging

    monkeypatch.delenv("LOG_FORMAT", raising=False)
    root = logging.getLogger()
    old_handlers, old_level = root.handlers[:], root.level
    try:
        configure_logging()
        assert isinstance(root.handlers[0].formatter, PrettyFormatter)
    finally:
        root.handlers, root.level = old_handlers, old_level


def test_configure_logging_can_keep_json_for_machine_consumers(monkeypatch):
    from app.core.logging import configure_logging

    monkeypatch.setenv("LOG_FORMAT", "json")
    root = logging.getLogger()
    old_handlers, old_level = root.handlers[:], root.level
    try:
        configure_logging()
        assert isinstance(root.handlers[0].formatter, JsonFormatter)
    finally:
        root.handlers, root.level = old_handlers, old_level


def test_create_app_uses_log_format_loaded_by_application_settings(monkeypatch):
    from app import main

    settings = SimpleNamespace(
        app_name="RAG AI Service",
        app_version="0.1.0",
        internal_api_key=None,
        log_format="pretty",
    )
    monkeypatch.setattr(main, "get_settings", lambda: settings)
    monkeypatch.setenv("LOG_FORMAT", "json")
    root = logging.getLogger()
    old_handlers, old_level = root.handlers[:], root.level
    try:
        main.create_app()
        assert isinstance(root.handlers[0].formatter, PrettyFormatter)
    finally:
        root.handlers, root.level = old_handlers, old_level


def test_operational_message_and_error_code_remain_useful():
    assert render("Request completed")["message"] == "Request completed"
    assert (
        render("Application exception: %s", ("local_resource_exhausted",))["message"]
        == "Application exception: local_resource_exhausted"
    )


def test_uvicorn_handlers_cannot_bypass_safe_formatter():
    from app.core.logging import configure_logging

    logger = logging.getLogger("uvicorn.error")
    old_handlers, old_propagate = logger.handlers[:], logger.propagate
    root = logging.getLogger()
    old_root, old_level = root.handlers[:], root.level
    try:
        leaked = io.StringIO()
        logger.handlers = [logging.StreamHandler(leaked)]
        logger.propagate = False
        configure_logging()
        logger.error("provider secret-token")
        assert leaked.getvalue() == ""
        assert logger.propagate
        assert not logger.handlers
    finally:
        logger.handlers, logger.propagate = old_handlers, old_propagate
        root.handlers, root.level = old_root, old_level


def test_untrusted_correlation_header_is_replaced_before_logging():
    from uuid import UUID

    from app.middleware.correlation_id import CorrelationIdMiddleware

    correlation = CorrelationIdMiddleware._resolve_correlation_id(
        {
            "headers": [
                (b"x-correlation-id", b"Bearer private-token; confidential question")
            ]
        }
    )
    assert str(UUID(correlation)) == correlation
    assert "private-token" not in correlation
    from app.core.logging import reset_correlation_id, set_correlation_id

    token = set_correlation_id(correlation)
    try:
        payload = render("Request completed")
        assert payload["correlation_id"] == correlation
        assert "private-token" not in json.dumps(payload)
        assert "confidential" not in json.dumps(payload)
    finally:
        reset_correlation_id(token)


def test_valid_uuid_correlation_header_is_preserved():
    from app.middleware.correlation_id import CorrelationIdMiddleware

    correlation = "4fc289d4-80f8-4fe4-b963-7bdbbaf73f7f"
    assert (
        CorrelationIdMiddleware._resolve_correlation_id(
            {"headers": [(b"x-correlation-id", correlation.encode())]}
        )
        == correlation
    )
