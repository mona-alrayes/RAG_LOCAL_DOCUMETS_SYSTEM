import json
import time
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from typing import Any, Protocol
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import HTTPRedirectHandler, OpenerDirector, Request, build_opener

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.schemas.documents import ProcessDocumentRequest

CALLBACK_SECRET_HEADER = "X-Processing-Callback-Secret"


@dataclass(frozen=True, slots=True)
class CallbackResponse:
    status_code: int
    payload: Mapping[str, Any] | None


class ProcessingProgressTransport(Protocol):
    def post_json(
        self,
        *,
        url: str,
        headers: Mapping[str, str],
        payload: Mapping[str, Any],
        timeout_seconds: float,
    ) -> CallbackResponse: ...


class ProcessingProgressNotifier(Protocol):
    def notify_indexing_started(
        self,
        *,
        request: ProcessDocumentRequest,
        correlation_id: str | None,
    ) -> None: ...


class _RejectRedirectHandler(HTTPRedirectHandler):
    def redirect_request(self, *args: Any, **kwargs: Any) -> Request | None:
        return None


class UrllibProcessingProgressTransport:
    def __init__(self) -> None:
        self._opener: OpenerDirector = build_opener(_RejectRedirectHandler())

    def post_json(
        self,
        *,
        url: str,
        headers: Mapping[str, str],
        payload: Mapping[str, Any],
        timeout_seconds: float,
    ) -> CallbackResponse:
        body = json.dumps(payload).encode("utf-8")
        http_request = Request(
            url=url,
            data=body,
            headers=dict(headers),
            method="POST",
        )

        try:
            with self._opener.open(
                http_request,
                timeout=timeout_seconds,
            ) as response:
                return CallbackResponse(
                    status_code=response.status,
                    payload=self._decode_payload(response.read()),
                )
        except HTTPError as exc:
            return CallbackResponse(
                status_code=exc.code,
                payload=self._decode_payload(exc.read()),
            )
        except (OSError, URLError) as exc:
            raise RuntimeError("Processing callback transport failed.") from exc

    @staticmethod
    def _decode_payload(body: bytes) -> Mapping[str, Any] | None:
        try:
            payload = json.loads(body)
        except (UnicodeDecodeError, json.JSONDecodeError):
            return None

        return payload if isinstance(payload, dict) else None


class LaravelProcessingProgressClient:
    def __init__(
        self,
        *,
        base_url: str,
        callback_secret: str,
        timeout_seconds: float,
        max_attempts: int,
        retry_delay_seconds: float,
        transport: ProcessingProgressTransport | None = None,
        sleeper: Callable[[float], None] = time.sleep,
    ) -> None:
        self._base_url = self._validate_base_url(base_url)

        if not callback_secret.strip():
            raise self._configuration_error()

        self._callback_secret = callback_secret
        self._timeout_seconds = timeout_seconds
        self._max_attempts = max_attempts
        self._retry_delay_seconds = retry_delay_seconds
        self._transport = transport or UrllibProcessingProgressTransport()
        self._sleeper = sleeper

    @classmethod
    def from_settings(cls, settings: Settings) -> "LaravelProcessingProgressClient":
        callback_secret = settings.processing_callback_secret
        base_url = settings.laravel_internal_base_url

        if (
            base_url is None
            or callback_secret is None
            or not callback_secret.get_secret_value().strip()
        ):
            raise cls._configuration_error()

        return cls(
            base_url=base_url,
            callback_secret=callback_secret.get_secret_value(),
            timeout_seconds=settings.processing_callback_timeout_seconds,
            max_attempts=settings.processing_callback_max_attempts,
            retry_delay_seconds=(
                settings.processing_callback_retry_delay_seconds
            ),
        )

    def notify_indexing_started(
        self,
        *,
        request: ProcessDocumentRequest,
        correlation_id: str | None,
    ) -> None:
        payload: dict[str, Any] = {
            "event": "indexing_started",
            "user_id": request.user_id,
            "document_id": request.document_id,
            "processing_run_id": request.processing_run_id,
        }

        if correlation_id:
            payload["correlation_id"] = correlation_id

        url = (
            f"{self._base_url}/internal/api/v1/processing-runs/"
            f"{request.processing_run_id}/events"
        )
        headers = {
            CALLBACK_SECRET_HEADER: self._callback_secret,
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

        last_error: Exception | None = None

        for attempt in range(1, self._max_attempts + 1):
            try:
                response = self._transport.post_json(
                    url=url,
                    headers=headers,
                    payload=payload,
                    timeout_seconds=self._timeout_seconds,
                )
            except RuntimeError as exc:
                last_error = exc
            else:
                if self._is_valid_ack(response, request):
                    return

                last_error = RuntimeError(
                    "Processing callback returned an invalid acknowledgement."
                )

                if (
                    300 <= response.status_code < 500
                    and response.status_code != 429
                ):
                    break

            if attempt < self._max_attempts:
                self._sleeper(self._retry_delay_seconds)

        raise ApplicationException(
            code="processing_progress_callback_failed",
            message="Processing progress callback failed.",
        ) from last_error

    @staticmethod
    def _is_valid_ack(
        response: CallbackResponse,
        request: ProcessDocumentRequest,
    ) -> bool:
        return (
            200 <= response.status_code < 300
            and response.payload is not None
            and response.payload.get("event") == "indexing_started"
            and response.payload.get("processing_run_id")
            == request.processing_run_id
            and response.payload.get("status") == "indexing"
        )

    @staticmethod
    def _validate_base_url(base_url: str) -> str:
        normalized = base_url.rstrip("/")
        parsed = urlparse(normalized)

        if (
            parsed.scheme not in {"http", "https"}
            or not parsed.netloc
            or parsed.username is not None
            or parsed.password is not None
        ):
            raise LaravelProcessingProgressClient._configuration_error()

        return normalized

    @staticmethod
    def _configuration_error() -> ApplicationException:
        return ApplicationException(
            code="processing_progress_callback_not_configured",
            message="Processing progress callback is not configured.",
        )
