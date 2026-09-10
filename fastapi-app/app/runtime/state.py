from threading import Lock
from typing import TYPE_CHECKING

from app.runtime.models import LocalRuntimeSnapshot

if TYPE_CHECKING:
    from app.runtime.model_coordinator import LocalModelCoordinator


class LocalRuntimeState:
    def __init__(self) -> None:
        self._snapshot: LocalRuntimeSnapshot | None = None
        self._lock = Lock()

    def set(self, snapshot: LocalRuntimeSnapshot) -> None:
        with self._lock:
            self._snapshot = snapshot

    def get(self) -> LocalRuntimeSnapshot | None:
        with self._lock:
            return self._snapshot

    def clear(self) -> None:
        with self._lock:
            self._snapshot = None


class LocalModelCoordinatorState:
    def __init__(self) -> None:
        self._coordinator: LocalModelCoordinator | None = None
        self._lock = Lock()

    def set(self, coordinator: "LocalModelCoordinator") -> None:
        with self._lock:
            self._coordinator = coordinator

    def get(self) -> "LocalModelCoordinator | None":
        with self._lock:
            return self._coordinator

    def clear(self) -> None:
        with self._lock:
            self._coordinator = None


local_runtime_state = LocalRuntimeState()
local_model_coordinator_state = LocalModelCoordinatorState()
