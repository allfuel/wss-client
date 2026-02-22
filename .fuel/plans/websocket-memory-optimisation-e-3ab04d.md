# Epic: WebSocket memory optimisation (e-3ab04d)

## Plan

<!-- Add implementation plan here -->

## Implementation Notes
- f-937a01: Frame masking uses native string XOR to avoid byte loop in `src/WebSocket/Frame.php`.
- f-937a01: Mask built via `str_repeat` + `substr` using `intdiv(($length + 3), 4)` to match payload length.
- f-937a01: Verified encode/decode roundtrip for payload sizes 0, 1, 4, 125, 65535, and 1MB.
- f-561a4c: Parser unmasking now uses native string XOR with expanded mask in `src/WebSocket/Parser.php`.
- f-561a4c: Added masked-frame parsing coverage for empty, small, medium (126), and large (127) payloads in `tests/ParserTest.php`.
- f-8ccff7: `sendClientEvent` now builds inner JSON first, unsets the payload before the outer encode, and avoids a named `$data` array in `src/Client.php`.
- f-c90de1: Added `Parser::MAX_BUFFER_SIZE` guard to `append` with `OverflowException` on overflow in `src/WebSocket/Parser.php`.
- f-c90de1: `Client::tick` catches buffer overflow and disconnects cleanly in `src/Client.php`.
- f-c90de1: Added buffer overflow and normal append coverage in `tests/ParserTest.php`.

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->
