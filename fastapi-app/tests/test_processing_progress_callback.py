from collections.abc import Mapping
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from threading import Thread
from typing import Any, ClassVar

import pytest

from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.schemas.documents import DocumentFileType, ProcessDocumentRequest
from app.services.processing_progress import (
    CALLBACK_SECRET_HEADER,
    CallbackResponse,
    LaravelProcessingProgressClient,
    UrllibProcessingProgressTransport,
)


class FakeTransport:
    def __init__(self, outcomes: list[CallbackResponse | Exception]) -> None:
        self.outcomes = outcomes
        self.calls: list[dict[str, Any]] = []

    def post_json(
        self,
        *,
        url: str,
        headers: Mapping[str, str],
        payload: Mapping[str, Any],
        timeout_seconds: float,
    ) -> CallbackResponse:
        self.calls.append(
            {
                "url": url,
                "headers": dict(headers),
                "payload": dict(payload),
                "timeout_seconds": timeout_seconds,
            }
        )
        outcome = self.outcomes.pop(0)

        if isinstance(outcome, Exception):
            raise outcome

        return outcome


class _RedirectHandler(BaseHTTPRequestHandler):
    redirect_to = ""
    received_secrets: ClassVar[list[str | None]] = []

    def do_POST(self) -> None:
        self.received_secrets.append(self.headers.get(CALLBACK_SECRET_HEADER))
        self.send_response(302)
        self.send_header("Location", self.redirect_to)
        self.end_headers()

    def log_message(self, message_format: str, *args: Any) -> None:
        pass


class _RedirectTargetHandler(BaseHTTPRequestHandler):
    received_secrets: ClassVar[list[str | None]] = []

    def do_GET(self) -> None:
        self._record_request()

    def do_POST(self) -> None:
        self._record_request()

    def _record_request(self) -> None:
        self.received_secrets.append(self.headers.get(CALLBACK_SECRET_HEADER))
        self.send_response(204)
        self.end_headers()

    def log_message(self, message_format: str, *args: Any) -> None:
        pass


def processing_request() -> ProcessDocumentRequest:
    return ProcessDocumentRequest(
        user_id=10,
        document_id=20,
        processing_run_id=30,
        processing_profile=ProcessingProfile.CLOUD,
        file_type=DocumentFileType.PDF,
    )


def valid_ack() -> CallbackResponse:
    return CallbackResponse(
        status_code=200,
        payload={
            "event": "indexing_started",
            "processing_run_id": 30,
            "status": "indexing",
        },
    )


def build_client(
    transport: FakeTransport,
    *,
    max_attempts: int = 3,
    sleeper=lambda _delay: None,
) -> LaravelProcessingProgressClient:
    return LaravelProcessingProgressClient(
        base_url="http://laravel.internal/",
        callback_secret="callback-only-secret",
        timeout_seconds=4.0,
        max_attempts=max_attempts,
        retry_delay_seconds=0.1,
        transport=transport,
        sleeper=sleeper,
    )


def test_callback_request_uses_trusted_url_ids_and_authentication_header() -> None:
    transport = FakeTransport([valid_ack()])
    client = build_client(transport)

    client.notify_indexing_started(
        request=processing_request(),
        correlation_id="h9-correlation-id",
    )

    assert len(transport.calls) == 1
    call = transport.calls[0]
    assert call["url"] == (
        "http://laravel.internal/internal/api/v1/processing-runs/30/events"
    )
    assert call["headers"][CALLBACK_SECRET_HEADER] == "callback-only-secret"
    assert call["payload"] == {
        "event": "indexing_started",
        "user_id": 10,
        "document_id": 20,
        "processing_run_id": 30,
        "correlation_id": "h9-correlation-id",
    }
    assert call["timeout_seconds"] == 4.0


def test_callback_retries_are_bounded() -> None:
    transport = FakeTransport(
        [
            CallbackResponse(status_code=503, payload=None),
            RuntimeError("connection unavailable"),
            valid_ack(),
        ]
    )
    delays: list[float] = []
    client = build_client(transport, sleeper=delays.append)

    client.notify_indexing_started(
        request=processing_request(),
        correlation_id=None,
    )

    assert len(transport.calls) == 3
    assert delays == [0.1, 0.1]


def test_callback_final_failure_is_safe_and_does_not_leak_secret(caplog) -> None:
    transport = FakeTransport(
        [CallbackResponse(status_code=503, payload=None)] * 3
    )
    client = build_client(transport)

    with pytest.raises(ApplicationException) as exc_info:
        client.notify_indexing_started(
            request=processing_request(),
            correlation_id=None,
        )

    assert len(transport.calls) == 3
    assert exc_info.value.code == "processing_progress_callback_failed"
    assert "callback-only-secret" not in str(exc_info.value)
    assert "callback-only-secret" not in repr(exc_info.value)
    assert "callback-only-secret" not in caplog.text


def test_non_retryable_callback_rejection_stops_immediately() -> None:
    transport = FakeTransport(
        [CallbackResponse(status_code=401, payload={"message": "Unauthorized"})]
    )
    client = build_client(transport)

    with pytest.raises(ApplicationException):
        client.notify_indexing_started(
            request=processing_request(),
            correlation_id=None,
        )

    assert len(transport.calls) == 1


def test_redirect_response_is_rejected_without_retry() -> None:
    transport = FakeTransport([CallbackResponse(status_code=302, payload=None)])
    client = build_client(transport)

    with pytest.raises(ApplicationException):
        client.notify_indexing_started(
            request=processing_request(),
            correlation_id=None,
        )

    assert len(transport.calls) == 1


def test_urllib_transport_does_not_forward_secret_across_redirect() -> None:
    _RedirectHandler.received_secrets = []
    _RedirectTargetHandler.received_secrets = []

    target_server = ThreadingHTTPServer(
        ("127.0.0.1", 0),
        _RedirectTargetHandler,
    )
    target_port = target_server.server_address[1]
    _RedirectHandler.redirect_to = f"http://127.0.0.1:{target_port}/capture"

    redirect_server = ThreadingHTTPServer(("127.0.0.1", 0), _RedirectHandler)
    redirect_port = redirect_server.server_address[1]
    target_thread = Thread(target=target_server.serve_forever, daemon=True)
    redirect_thread = Thread(target=redirect_server.serve_forever, daemon=True)
    target_thread.start()
    redirect_thread.start()

    try:
        response = UrllibProcessingProgressTransport().post_json(
            url=f"http://127.0.0.1:{redirect_port}/callback",
            headers={CALLBACK_SECRET_HEADER: "callback-only-secret"},
            payload={"event": "indexing_started"},
            timeout_seconds=1.0,
        )
    finally:
        redirect_server.shutdown()
        target_server.shutdown()
        redirect_server.server_close()
        target_server.server_close()
        redirect_thread.join()
        target_thread.join()

    assert response.status_code == 302
    assert _RedirectHandler.received_secrets == ["callback-only-secret"]
    assert _RedirectTargetHandler.received_secrets == []
