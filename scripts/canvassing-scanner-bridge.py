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
        --device /dev/input/by-id/usb-LWTEK_Barcode_Scanner_00000000011C-event-kbd \
        | php artisan election:canvassing-scanner-ingest --station-id=canvassing-demo-city

Run `python3 scripts/canvassing-scanner-bridge.py --list` to discover
candidate keyboard-class input devices if the --device path changes.
"""

from __future__ import annotations

import argparse
import sys

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


def find_scanner_device(name_hint: str) -> str | None:
    for path in evdev.list_devices():
        device = evdev.InputDevice(path)
        if name_hint.lower() in device.name.lower():
            return path
    return None


def list_devices() -> None:
    for path in evdev.list_devices():
        device = evdev.InputDevice(path)
        print(f"{path}\t{device.name}")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--device",
        help="Path to the scanner's evdev device node "
        "(e.g. /dev/input/by-id/usb-LWTEK_Barcode_Scanner_00000000011C-event-kbd)",
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
    args = parser.parse_args()

    if args.list:
        list_devices()
        return 0

    device_path = args.device or find_scanner_device(args.name_hint)

    if device_path is None:
        sys.stderr.write(
            "Could not find a scanner input device. "
            "Run with --list to see available devices, "
            "then pass --device explicitly.\n"
        )
        return 1

    try:
        device = evdev.InputDevice(device_path)
    except PermissionError:
        sys.stderr.write(
            f"Permission denied opening {device_path}. "
            "Add this user to the 'input' group and re-login: "
            "sudo usermod -aG input $(whoami)\n"
        )
        return 1

    shift_held = False
    buffer: list[str] = []

    for event in device.read_loop():
        if event.type != ecodes.EV_KEY:
            continue

        # value: 0 = key up, 1 = key down, 2 = autorepeat.
        if event.code in SHIFT_KEYS:
            shift_held = event.value != 0
            continue

        if event.value != 1:
            continue

        if event.code in ENTER_KEYS:
            payload = "".join(buffer).strip()
            buffer = []

            if payload != "":
                print(payload, flush=True)

            continue

        chars = KEY_CHAR_MAP.get(event.code)

        if chars is None:
            continue

        buffer.append(chars[1] if shift_held else chars[0])

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
