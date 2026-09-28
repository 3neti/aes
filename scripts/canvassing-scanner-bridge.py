#!/usr/bin/env python3
"""Reads raw HID keyboard events from a USB keyboard-wedge barcode scanner
and prints each completed scan as a line on stdout.

This bypasses the normal TTY/console-focus keyboard routing: HID keyboard
events from a USB scanner are normally delivered to whichever session has
local console focus (an X/Wayland session or a getty on a virtual
terminal), never to a remote SSH session. Reading the scanner's evdev
device node directly avoids that problem entirely, and is also immune to
terminal line-discipline signal handling (e.g. a Ctrl-C/ETX byte from the
scanner killing the process via SIGINT), since raw evdev events are not
interpreted by any TTY.

Intended usage on the device:

    sudo usermod -aG input ace   # one-time, then re-login
    sudo apt-get install -y python3-evdev

    python3 scripts/canvassing-scanner-bridge.py \
        --device /dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd \
        | php artisan election:canvassing-scanner-ingest --station-id=canvassing-demo-city

Run `python3 scripts/canvassing-scanner-bridge.py --list` to discover
candidate keyboard-class input devices if the --device path changes.
"""

from __future__ import annotations

import argparse
import json
import os
import select
import socket
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

try:
    import evdev
    from evdev import ecodes
except ImportError:  # pragma: no cover - guidance path, not exercised in tests
    sys.stderr.write(
        "Missing dependency: python3-evdev.\n"
        "Install it with: sudo apt-get install -y python3-evdev\n"
    )
    raise SystemExit(1)

# Maps evdev KEY_* codes to the character they produce, unshifted and
# shifted. Covers the standard US QWERTY layout, which is the default
# emulation mode for the vast majority of keyboard-wedge barcode scanners.
KEY_CHAR_MAP: dict[int, tuple[str, str]] = {
    ecodes.KEY_1: ("1", "!"), ecodes.KEY_2: ("2", "@"), ecodes.KEY_3: ("3", "#"),
    ecodes.KEY_4: ("4", "$"), ecodes.KEY_5: ("5", "%"), ecodes.KEY_6: ("6", "^"),
    ecodes.KEY_7: ("7", "&"), ecodes.KEY_8: ("8", "*"), ecodes.KEY_9: ("9", "("),
    ecodes.KEY_0: ("0", ")"), ecodes.KEY_MINUS: ("-", "_"), ecodes.KEY_EQUAL: ("=", "+"),
    ecodes.KEY_A: ("a", "A"), ecodes.KEY_B: ("b", "B"), ecodes.KEY_C: ("c", "C"),
    ecodes.KEY_D: ("d", "D"), ecodes.KEY_E: ("e", "E"), ecodes.KEY_F: ("f", "F"),
    ecodes.KEY_G: ("g", "G"), ecodes.KEY_H: ("h", "H"), ecodes.KEY_I: ("i", "I"),
    ecodes.KEY_J: ("j", "J"), ecodes.KEY_K: ("k", "K"), ecodes.KEY_L: ("l", "L"),
    ecodes.KEY_M: ("m", "M"), ecodes.KEY_N: ("n", "N"), ecodes.KEY_O: ("o", "O"),
    ecodes.KEY_P: ("p", "P"), ecodes.KEY_Q: ("q", "Q"), ecodes.KEY_R: ("r", "R"),
    ecodes.KEY_S: ("s", "S"), ecodes.KEY_T: ("t", "T"), ecodes.KEY_U: ("u", "U"),
    ecodes.KEY_V: ("v", "V"), ecodes.KEY_W: ("w", "W"), ecodes.KEY_X: ("x", "X"),
    ecodes.KEY_Y: ("y", "Y"), ecodes.KEY_Z: ("z", "Z"),
    ecodes.KEY_SLASH: ("/", "?"), ecodes.KEY_BACKSLASH: ("\\", "|"),
    ecodes.KEY_SEMICOLON: (";", ":"), ecodes.KEY_APOSTROPHE: ("'", '"'),
    ecodes.KEY_COMMA: (",", "<"), ecodes.KEY_DOT: (".", ">"),
    ecodes.KEY_LEFTBRACE: ("[", "{"), ecodes.KEY_RIGHTBRACE: ("]", "}"),
    ecodes.KEY_GRAVE: ("`", "~"), ecodes.KEY_SPACE: (" ", " "),
    ecodes.KEY_TAB: ("\t", "\t"),
    ecodes.KEY_KP0: ("0", "0"), ecodes.KEY_KP1: ("1", "1"), ecodes.KEY_KP2: ("2", "2"),
    ecodes.KEY_KP3: ("3", "3"), ecodes.KEY_KP4: ("4", "4"), ecodes.KEY_KP5: ("5", "5"),
    ecodes.KEY_KP6: ("6", "6"), ecodes.KEY_KP7: ("7", "7"), ecodes.KEY_KP8: ("8", "8"),
    ecodes.KEY_KP9: ("9", "9"), ecodes.KEY_KPSLASH: ("/", "/"),
    ecodes.KEY_KPASTERISK: ("*", "*"), ecodes.KEY_KPMINUS: ("-", "-"),
    ecodes.KEY_KPPLUS: ("+", "+"), ecodes.KEY_KPDOT: (".", "."),
}

ENTER_KEYS = {ecodes.KEY_ENTER, ecodes.KEY_KPENTER}
SHIFT_KEYS = {ecodes.KEY_LEFTSHIFT, ecodes.KEY_RIGHTSHIFT}
CTRL_KEYS = {ecodes.KEY_LEFTCTRL, ecodes.KEY_RIGHTCTRL}


def find_scanner_device(name_hint: str) -> str | None:
    for path in evdev.list_devices():
        device = evdev.InputDevice(path)
        try:
            if name_hint.lower() in device.name.lower():
                return path
        finally:
            device.close()
    return None


def list_devices() -> None:
    for path in evdev.list_devices():
        device = evdev.InputDevice(path)
        try:
            print(f"{path}\t{device.name}")
        finally:
            device.close()


def record_heartbeat(path: Path | None, device_path: str) -> None:
    if path is None:
        return

    path.parent.mkdir(parents=True, exist_ok=True)
    heartbeat = {
        "schema_version": "waes-runtime-heartbeat-1",
        "service": "scanner",
        "recorded_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "process_id": os.getpid(),
        "host": socket.gethostname(),
        "device": device_path,
    }
    temporary_path = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    temporary_path.write_text(
        json.dumps(heartbeat, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )
    os.replace(temporary_path, path)


def emit_buffer(buffer: list[str]) -> None:
    payload = "".join(buffer).strip()
    buffer.clear()

    if payload != "":
        print(payload, flush=True)


def read_scanner(
    device: evdev.InputDevice,
    heartbeat_path: Path | None,
    heartbeat_interval: float,
) -> None:
    shift_held = False
    ctrl_held = False
    buffer: list[str] = []

    record_heartbeat(heartbeat_path, device.path)

    while True:
        readable, _, _ = select.select(
            [device.fd],
            [],
            [],
            heartbeat_interval,
        )

        if not readable:
            record_heartbeat(heartbeat_path, device.path)
            continue

        for event in device.read():
            if event.type != ecodes.EV_KEY:
                continue

            # value: 0 = key up, 1 = key down, 2 = autorepeat.
            if event.code in SHIFT_KEYS:
                shift_held = event.value != 0
                continue

            if event.code in CTRL_KEYS:
                ctrl_held = event.value != 0
                continue

            if event.value != 1:
                continue

            if event.code in ENTER_KEYS or (ctrl_held and event.code == ecodes.KEY_C):
                emit_buffer(buffer)
                record_heartbeat(heartbeat_path, device.path)
                continue

            chars = KEY_CHAR_MAP.get(event.code)

            if chars is None:
                continue

            buffer.append(chars[1] if shift_held else chars[0])


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--device",
        help="Path to the scanner's evdev device node "
        "(e.g. /dev/input/by-id/usb-Scanner_Barcode_0215-event-kbd)",
    )
    parser.add_argument(
        "--name-hint",
        default="barcode",
        help="Case-insensitive substring to search for in device names when "
        "--device is not given (default: 'barcode')",
    )
    parser.add_argument(
        "--list",
        action="store_true",
        help="List available input devices and exit",
    )
    parser.add_argument(
        "--heartbeat-path",
        help="Write an atomic scanner heartbeat JSON file while the device is open",
    )
    parser.add_argument(
        "--heartbeat-interval",
        type=float,
        default=30.0,
        help="Seconds between scanner heartbeats while idle (default: 30)",
    )
    parser.add_argument(
        "--reconnect-delay",
        type=float,
        default=2.0,
        help="Seconds before retrying a missing or disconnected scanner (default: 2)",
    )
    args = parser.parse_args()

    if args.list:
        list_devices()
        return 0

    heartbeat_path = Path(args.heartbeat_path) if args.heartbeat_path else None
    heartbeat_interval = max(1.0, args.heartbeat_interval)
    reconnect_delay = max(0.25, args.reconnect_delay)

    while True:
        device_path = args.device or find_scanner_device(args.name_hint)

        if device_path is None:
            sys.stderr.write(
                "Scanner input device is not present; retrying. "
                "Run with --list to inspect available devices.\n"
            )
            time.sleep(reconnect_delay)
            continue

        try:
            device = evdev.InputDevice(device_path)
            device.grab()
            sys.stderr.write(f"Scanner connected: {device.path} ({device.name})\n")

            try:
                read_scanner(device, heartbeat_path, heartbeat_interval)
            finally:
                try:
                    device.ungrab()
                except OSError:
                    pass
                device.close()
        except PermissionError:
            sys.stderr.write(
                f"Permission denied opening {device_path}. "
                "Add this user to the 'input' group and restart the service.\n"
            )
            return 1
        except BrokenPipeError:
            sys.stderr.write("Scanner ingestion consumer closed the pipeline.\n")
            return 1
        except OSError as exception:
            sys.stderr.write(
                f"Scanner disconnected or unavailable ({exception}); retrying.\n"
            )
            time.sleep(reconnect_delay)


if __name__ == "__main__":
    raise SystemExit(main())
