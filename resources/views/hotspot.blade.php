<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Connect to WAES</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #eef3f9; color: #17243a; font: 16px/1.55 system-ui, -apple-system, sans-serif; }
            main { width: 100%; max-width: 440px; padding: 36px 28px; border: 1px solid #d8e2ef; border-radius: 20px; background: #fff; text-align: center; box-shadow: 0 16px 48px #17243a0d; }
            .brand { margin: 0 0 20px; color: #1d4ed8; font-size: 14px; font-weight: 800; letter-spacing: .2em; }
            h1 { margin: 0 0 12px; font-size: 30px; line-height: 1.2; letter-spacing: -.03em; }
            p { margin: 0 0 20px; color: #4b5e78; }
            button { width: 100%; margin: 4px 0 20px; padding: 15px 20px; border: 0; border-radius: 11px; background: #1d4ed8; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
            button:hover { background: #1e40af; }
            button:focus-visible { outline: 3px solid #93c5fd; outline-offset: 3px; }
            .note { margin-bottom: 0; font-size: 13px; }
            .address { display: block; margin-top: 8px; color: #1d4ed8; overflow-wrap: anywhere; }
        </style>
    </head>
    <body>
        <main>
            <p class="brand">WAES</p>
            <h1>Connect to WAES</h1>
            <p>Open the local election demo.<br>No password or Internet connection is required.</p>
            <form method="POST" action="{{ $connectUrl }}">
                @csrf
                <button type="submit">Connect to WAES</button>
            </form>
            <p class="note">
                Internet access is available only when an uplink is connected.
                You can always open WAES in your browser at:
                <span class="address">{{ $baseUrl }}</span>
            </p>
        </main>
    </body>
</html>
