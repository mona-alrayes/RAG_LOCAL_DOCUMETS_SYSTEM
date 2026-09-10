import logging
import uuid

from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.core.logging import reset_correlation_id, set_correlation_id


CORRELATION_ID_HEADER = b"x-correlation-id"
logger = logging.getLogger("app.request")


class CorrelationIdMiddleware:
    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(
        self,
        scope: Scope,
        receive: Receive,
        send: Send,
    ) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        correlation_id = self._resolve_correlation_id(scope)
        token = set_correlation_id(correlation_id)

        async def send_with_correlation_id(message: Message) -> None:
            if message["type"] == "http.response.start":
                headers = list(message.get("headers", []))
                headers.append(
                    (
                        CORRELATION_ID_HEADER,
                        correlation_id.encode("ascii"),
                    )
                )
                message["headers"] = headers

            await send(message)

        try:
            await self.app(scope, receive, send_with_correlation_id)
            logger.info("Request completed")
        finally:
            reset_correlation_id(token)

    @staticmethod
    def _resolve_correlation_id(scope: Scope) -> str:
        for name, value in scope.get("headers", []):
            if name == CORRELATION_ID_HEADER:
                try:
                    correlation_id = value.decode("ascii").strip()
                except UnicodeDecodeError:
                    break

                if correlation_id and len(correlation_id) <= 128:
                    return correlation_id

        return str(uuid.uuid4())
