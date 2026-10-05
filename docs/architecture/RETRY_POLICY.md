# Centralized Retry Policy Specification

**Service:** `App\Services\PublishingRetryPolicy`  
**Classification:** Canonical 12-Category Error Taxonomy  

---

## 1. Error Categories and Policy Configuration

The retry engine classifies raw provider exceptions into 12 standardized canonical categories, determining retryability, maximum attempts, base backoff, and randomized jitter:

| Category | Retryable | Max Attempts | Base Backoff | Jitter Range | Standard User Message |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **AUTHENTICATION** | **No** | 1 | 0s | 0s | Social account authentication expired or invalid. Please reconnect your account. |
| **AUTHORIZATION** | **No** | 1 | 0s | 0s | Missing required permissions for this publishing operation. Please grant permissions. |
| **RATE_LIMITED** | **Yes** | 5 | 60s | 15s | Publishing temporarily rate limited by provider. Retrying automatically. |
| **NETWORK** | **Yes** | 4 | 10s | 5s | Transient network communication error with provider. Retrying automatically. |
| **TIMEOUT** | **Yes** | 3 | 20s | 5s | Connection or remote upload timed out. Retrying automatically. |
| **PROVIDER_UNAVAILABLE** | **Yes** | 4 | 30s | 10s | Provider API service is temporarily unavailable. Retrying automatically. |
| **VALIDATION** | **No** | 1 | 0s | 0s | Content or metadata failed platform validation requirements. |
| **MEDIA_INVALID** | **No** | 1 | 0s | 0s | Media file does not meet the provider technical specifications. |
| **QUOTA_EXCEEDED** | **No** | 1 | 0s | 0s | Provider publishing quota exceeded for today. Please wait for quota reset. |
| **DUPLICATE** | **No** | 1 | 0s | 0s | Duplicate submission detected. Post was already created on the provider. |
| **PERMANENT_PROVIDER_ERROR** | **No** | 1 | 0s | 0s | Provider rejected the publication request permanently. |
| **UNKNOWN** | **Yes** | 2 | 15s | 5s | An unexpected error occurred during publishing. Retrying. |

---

## 2. Exponential Backoff Formula with Jitter

For retryable categories, the scheduled delay is calculated as:

$$\text{Backoff} = \left(\text{Base Backoff} \times 2^{\text{attempt} - 1}\right) + \text{rand}(1, \text{Jitter})$$

### Example Backoff Schedule (RATE_LIMITED):
- **Attempt 1:** $60 \times 2^0 + \text{rand}(1, 15) \approx 61\text{s} - 75\text{s}$
- **Attempt 2:** $60 \times 2^1 + \text{rand}(1, 15) \approx 121\text{s} - 135\text{s}$
- **Attempt 3:** $60 \times 2^2 + \text{rand}(1, 15) \approx 241\text{s} - 255\text{s}$
- **Attempt 4:** $60 \times 2^3 + \text{rand}(1, 15) \approx 481\text{s} - 495\text{s}$

---

## 3. Exclusion Rules

The following classes of failures are **strictly non-retryable**:
1. Expired or revoked OAuth access / refresh tokens (`AUTHENTICATION`).
2. Missing OAuth scopes or permission denial (`AUTHORIZATION`).
3. Rejected media formats, corrupted file headers, or duration limit violations (`MEDIA_INVALID`).
4. Daily publishing quota exhaustion (`QUOTA_EXCEEDED`).
5. Redundant duplicate uploads detected (`DUPLICATE`).
6. Unrecoverable content policy or moderation blocks (`PERMANENT_PROVIDER_ERROR`).
