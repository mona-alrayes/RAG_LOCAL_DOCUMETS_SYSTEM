from app.runtime.models import RuntimeBackend, RuntimeDtype


class TorchRuntimeAdapter:
    def is_available(self, backend: RuntimeBackend) -> bool:
        torch = self._load_torch()

        if backend is RuntimeBackend.CUDA:
            return (
                torch.cuda.is_available()
                and torch.version.cuda is not None
                and torch.version.hip is None
            )

        if backend is RuntimeBackend.ROCM:
            return (
                torch.cuda.is_available()
                and torch.version.hip is not None
            )

        if backend is RuntimeBackend.XPU:
            return (
                hasattr(torch, "xpu")
                and torch.xpu.is_available()
            )

        if backend is RuntimeBackend.MPS:
            return (
                hasattr(torch.backends, "mps")
                and torch.backends.mps.is_available()
            )

        if backend is RuntimeBackend.CPU:
            return True

        return False

    def probe(
        self,
        backend: RuntimeBackend,
        dtype: RuntimeDtype,
    ) -> None:
        torch = self._load_torch()

        if not self.is_available(backend):
            raise RuntimeError(
                f"Requested local backend is unavailable: {backend.value}"
            )

        device = self._device_name(backend)
        torch_dtype = self._torch_dtype(torch, dtype)

        tensor = None
        result = None

        try:
            tensor = torch.ones(
                (2, 2),
                device=device,
                dtype=torch_dtype,
            )

            result = (tensor * 2).sum()

            self._synchronize(torch, backend)

            if float(result.item()) != 8.0:
                raise RuntimeError(
                    "Runtime probe returned an invalid result "
                    f"for backend: {backend.value}"
                )
        finally:
            del result
            del tensor
            self.release_cache(backend)

    def accelerator_memory(
        self,
        backend: RuntimeBackend,
    ) -> tuple[int | None, int | None]:
        torch = self._load_torch()

        if backend in (
            RuntimeBackend.CUDA,
            RuntimeBackend.ROCM,
        ):
            return (
                torch.cuda.memory_allocated(),
                torch.cuda.memory_reserved(),
            )

        if backend is RuntimeBackend.XPU:
            return (
                torch.xpu.memory_allocated(),
                torch.xpu.memory_reserved(),
            )

        if backend is RuntimeBackend.MPS:
            return (
                torch.mps.current_allocated_memory(),
                torch.mps.driver_allocated_memory(),
            )

        return None, None

    def release_cache(self, backend: RuntimeBackend) -> None:
        torch = self._load_torch()

        if backend in (
            RuntimeBackend.CUDA,
            RuntimeBackend.ROCM,
        ):
            torch.cuda.empty_cache()
            return

        if backend is RuntimeBackend.XPU:
            torch.xpu.empty_cache()
            return

        if backend is RuntimeBackend.MPS:
            torch.mps.empty_cache()

    def _device_name(self, backend: RuntimeBackend) -> str:
        if backend in (
            RuntimeBackend.CUDA,
            RuntimeBackend.ROCM,
        ):
            return "cuda"

        return backend.value

    def _torch_dtype(self, torch, dtype: RuntimeDtype):
        if dtype is RuntimeDtype.FP16:
            return torch.float16

        return torch.float32

    def _synchronize(self, torch, backend: RuntimeBackend) -> None:
        if backend in (
            RuntimeBackend.CUDA,
            RuntimeBackend.ROCM,
        ):
            torch.cuda.synchronize()
            return

        if backend is RuntimeBackend.XPU:
            torch.xpu.synchronize()
            return

        if backend is RuntimeBackend.MPS:
            torch.mps.synchronize()

    def _load_torch(self):
        try:
            import torch
        except ModuleNotFoundError as exc:
            raise RuntimeError(
                "Local PyTorch runtime is not installed."
            ) from exc

        return torch
