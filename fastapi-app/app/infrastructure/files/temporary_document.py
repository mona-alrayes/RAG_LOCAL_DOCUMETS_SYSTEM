from collections.abc import Iterator
from contextlib import contextmanager
from pathlib import Path
from shutil import copyfileobj
from tempfile import NamedTemporaryFile
from typing import BinaryIO

from app.core.exceptions import ApplicationException


@contextmanager
def temporary_document(
    source: BinaryIO,
    *,
    suffix: str,
) -> Iterator[Path]:
    temporary_path: Path | None = None

    try:
        try:
            with NamedTemporaryFile(
                mode="wb",
                suffix=suffix,
                delete=False,
            ) as temporary_file:
                copyfileobj(source, temporary_file)
                temporary_path = Path(temporary_file.name)

        except Exception as exc:
            raise ApplicationException(
                code="temporary_document_failed",
                message=(
                    "Unable to prepare the uploaded document "
                    "for processing."
                ),
            ) from exc

        yield temporary_path

    finally:
        if temporary_path is not None:
            temporary_path.unlink(missing_ok=True)
