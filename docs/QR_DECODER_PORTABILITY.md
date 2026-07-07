# QR Decoder Portability

The current QR decode path is intentionally adapter-based:

- `App\Election\Voting\QrCodeDecoder`
- `App\Election\Voting\ZbarPngQrCodeDecoder`
- `App\Election\Voting\StandardQrCode`

`ZbarPngQrCodeDecoder` remains the default because it is already verified by the existing lifecycle, camera scanner, and browser tests. It depends on the local `zbarimg` binary, which is a deployment concern for Raspberry Pi appliances.

## Current Position

Do not add a PHP QR decode dependency until a candidate proves all of the following:

- Works offline with no network callouts.
- Decodes the AES-generated QR PNG artifacts deterministically.
- Handles camera-captured PNG data URIs produced by the Counting ceremony.
- Has a stable license and maintainable release history.
- Does not pull in broad image-processing or web-service dependencies.
- Can be installed reproducibly on the target appliance image.

## Evaluation Checklist

For any candidate decoder:

1. Add it behind `QrCodeDecoder`; do not change election services.
2. Run `vendor/bin/pest tests/Feature/Election/ElectionLifecycleTest.php --compact`.
3. Run `vendor/bin/pest tests/Feature/Election/ElectionPagesSmokeTest.php --compact`.
4. Run `vendor/bin/pest tests/Browser/CountingCameraCaptureWorkflowTest.php --compact`.
5. Verify Friday certification and full-demo scenarios.
6. Compare decoded payload strings byte-for-byte against the current `zbarimg` adapter.
7. Record appliance install steps in deployment notes.

## Fallback Strategy

Keep `ZbarPngQrCodeDecoder` available even if a pure PHP decoder is introduced. A production appliance should be able to select the decoder adapter through configuration after the pure PHP path has passed field tests.
