import secrets

from pydantic import SecretStr
from starlette.responses import JSONResponse
from starlette.types import ASGIApp, Receive, Scope, Send


INTERNAL_API_KEY_HEADER = b"x-internal-api-key"


class InternalApiAuthMiddleware:
    def __init__(
        self,
        app: ASGIApp,
        internal_api_key: SecretStr | None,
    ) -> None:
        self.app = app
        self.internal_api_key = internal_api_key

    async def __call__(
        self,
        scope: Scope,
        receive: Receive,
        send: Send,
    ) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        provided_key = self._get_provided_key(scope)

        if not self._is_authorized(provided_key):
            response = JSONResponse(
                status_code=401,
                content={"detail": "Unauthorized"},
            )
            await response(scope, receive, send)
            return

        await self.app(scope, receive, send)

    @staticmethod
    def _get_provided_key(scope: Scope) -> bytes | None:
        for name, value in scope.get("headers", []):
            if name == INTERNAL_API_KEY_HEADER:
                return value

        return None

    def _is_authorized(self, provided_key: bytes | None) -> bool:
        if self.internal_api_key is None or not provided_key:
            return False

        expected_key = self.internal_api_key.get_secret_value().encode("utf-8")

        if not expected_key:
            return False

        return secrets.compare_digest(provided_key, expected_key)
