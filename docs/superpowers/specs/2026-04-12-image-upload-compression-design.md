# Design Spec: Image Upload Compression For Mobile Camera Captures

Date: 2026-04-12
Status: Approved for planning

## 1. Problem Statement

Mobile users can capture images directly from the phone camera in the PWA upload flows. These images are often very large (for example 10-50 MB), which causes avoidable upload failures and unnecessary storage usage.

The product goal is:
- Keep receipt images readable.
- Allow direct camera/photo uploads without friction.
- Enforce strict size boundaries.

## 2. Final Decisions (Validated)

- Image target size: 2 MB per image.
- If image compression still cannot reach 2 MB: block upload with clear message.
- Non-image files: allowed up to 10 MB.
- Image compression target format: JPEG.
- Camera forcing via `capture="environment"`: no. Keep normal file chooser behavior.
- Scope:
  - All image uploads using `attachments[]`.
  - App logo upload included.

## 3. Approaches Considered

### A) Client compression + server validation (selected)

Description:
- Browser compresses image files before upload.
- Server always re-validates final files.

Pros:
- Best user experience on mobile.
- Fewer failed uploads from huge camera files.
- Storage use is controlled before persistence.
- Security remains server authoritative.

Cons:
- More frontend complexity.
- Must handle browser-specific image decoding issues.

### B) Server-only hard limits

Description:
- No client compression.
- Server rejects image files > 2 MB.

Pros:
- Simpler implementation.
- Strong and clear enforcement.

Cons:
- Poor UX for mobile captures.
- Frequent retries and manual user work.

### C) Dedicated image-processing endpoint/service

Description:
- Route image uploads through a specialized processing path.

Pros:
- Strong separation of concerns.
- Potentially reusable for future media workflows.

Cons:
- Highest implementation and maintenance cost.
- Not needed for current scope.

## 4. Architecture

### 4.1 Frontend responsibility

A shared frontend upload helper will:
- Intercept file selection and/or submit for configured forms.
- Process only image files.
- Convert processed image files to JPEG.
- Apply size reduction strategy until file size <= 2 MB or minimum quality boundary is reached.
- Keep non-image files untouched.
- Provide immediate, file-specific error messages.

### 4.2 Backend responsibility

Each affected upload controller validates incoming files regardless of frontend behavior:
- Image file > 2 MB: reject.
- Non-image file > 10 MB: reject.
- Existing MIME allow-lists remain in place per domain rules.

Backend is the final authority for security and consistency.

### 4.3 Data flow

1. User selects files (camera or gallery).
2. Frontend evaluates each file:
   - Image: compress and convert to JPEG target.
   - Non-image: pass through unchanged.
3. If any image remains > 2 MB after compression bounds: block submit and show explicit error.
4. Submit transformed file set.
5. Server validates and persists accepted files.

## 5. Component Design

## 5.1 Frontend components

- A dedicated JS module (shared, not inline in Twig templates).
- Configuration by selector or explicit form/input registration for:
  - Finance attachments
  - Task attachments
  - Sponsorship attachments
  - Song library attachments
  - App logo upload

The helper exposes:
- Size constants (2 MB image, 10 MB non-image).
- Compression routine with iterative quality/decode handling.
- Validation result object for UI messages.

## 5.2 Backend harmonization

Controllers currently have inconsistent limits. Implementation planning must harmonize validation rules to:
- image_max_bytes = 2 * 1024 * 1024
- non_image_max_bytes = 10 * 1024 * 1024

All affected upload paths must return clear, consistent error feedback.

## 6. Error Handling And Edge Cases

Handled cases:
- Very large camera images (20-50 MB).
- Mixed batch uploads (images + PDFs).
- Unsupported/undecodable image formats in certain browsers (for example HEIC in unsupported contexts).
- Empty files.
- Files that remain too large after minimum quality threshold.

Behavior:
- Per-file errors identify file name and reason.
- Failed files are not persisted.
- User can retry with better capture or alternative file.

## 7. Security And Compliance Notes

- Server-side validation is mandatory and non-optional.
- Client-side checks are usability optimizations only.
- No external runtime libraries via CDN.
- Existing authorization and ownership checks stay unchanged.

## 8. Testing Strategy

## 8.1 Backend feature tests

Add/update tests for each upload domain to verify:
- Image <= 2 MB accepted.
- Image > 2 MB rejected.
- Non-image <= 10 MB accepted.
- Non-image > 10 MB rejected.
- No file persisted on rejection.
- User-facing error message is set.

## 8.2 Frontend tests

Add JS tests for compression and validation helper:
- Compresses oversized image below threshold when possible.
- Fails with expected error when threshold cannot be reached.
- Leaves non-image files unchanged.
- Correct handling of mixed file arrays.

## 8.3 Regression checks

- Existing non-upload flows unchanged.
- Existing permission checks unchanged.
- Existing allowed MIME rules still enforced.

## 9. Rollout And Observability

- Roll out with helper text near file inputs (images auto-optimized to 2 MB, other files max 10 MB).
- Monitor upload error frequency after release.
- Tune frontend quality/resize heuristics if readability or failure rate indicates need.

## 10. Out Of Scope

- Introducing a dedicated media microservice.
- Background image processing queues.
- OCR extraction pipeline.
- Forced camera capture behavior.

## 11. Open Questions

None. The required behavior for limits, compression strategy, format, scope, and failure policy has been defined.
