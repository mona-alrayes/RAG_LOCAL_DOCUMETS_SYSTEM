import json
import logging

from app.core.logging import JsonFormatter


def test_sdk_diagnostics_keep_status_and_mask_credentials():
    record = logging.LogRecord(
        "httpx",
        logging.INFO,
        __file__,
        1,
        "HTTP 429 retry in %s seconds api_key=private123 Authorization: Bearer private456",
        (60,),
        None,
    )
    result = json.loads(JsonFormatter().format(record))
    assert "HTTP 429 retry in 60 seconds" in result["message"]
    assert "private123" not in result["message"]
    assert "private456" not in result["message"]


def test_traceback_preserves_cause_and_masks_password():
    try:
        try:
            raise TimeoutError('connection timed out password="private words"')
        except TimeoutError as cause:
            raise RuntimeError("document parsing failed") from cause
    except RuntimeError as error:
        record = logging.LogRecord(
            "app",
            logging.ERROR,
            __file__,
            1,
            "failure",
            (),
            (type(error), error, error.__traceback__),
        )
        result = json.loads(JsonFormatter().format(record))
    assert "TimeoutError" in result["traceback"]
    assert "connection timed out" in result["traceback"]
    assert "private words" not in result["traceback"]
