# Security

## Reporting security issues

If you believe you have found a security vulnerability in `atelier/barcode`,
please report it through coordinated disclosure.

> [!IMPORTANT]
> **Do not report security vulnerabilities through public GitHub issues.**
>
> Report by email at [smnandre@gmail.com](mailto:smnandre@gmail.com).
> You should receive an acknowledgment within 48 hours, followed by a security
> patch as quickly as possible.

Include what you have:

- a description of the vulnerability;
- steps to reproduce, with the payload and symbology involved;
- the PHP version and operating system used.

Encoded payloads are untrusted input by design. Reports about a symbology
accepting data it should reject, producing a code that scans as something other
than the input, or emitting output that escapes the SVG text encoding are in
scope.

## Supported versions

Only the latest minor release receives security fixes.

## Preferred languages

English or French.

## Security policy

This project follows
[Coordinated Vulnerability Disclosure](https://en.wikipedia.org/wiki/Coordinated_vulnerability_disclosure).
