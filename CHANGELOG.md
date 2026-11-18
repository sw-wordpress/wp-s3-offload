# Unreleased
 - Add code quality / formatting checks ported from sw-backupit: PHPCS (WordPress Coding Standards + PHPCompatibilityWP), PHPStan level 8 with phpstan-wordpress, composer validate/audit, GitHub Actions workflow
 - Update aws/aws-sdk-php to 3.405.0 and transitive dependencies (fixes 16 security advisories in the previously pinned 3.288.x set)
 - Security: nonce and capability checks for the admin-post sync/test-connection endpoints, escaped output, sanitized request input, proper text domain
 - Fix Redis cache handler (wrong setEx usage, inverted remove() logic, missing return in Syncer::getCacheHandler(), debug output)
 - Fix Slider Revolution integration: guard against missing plugin, prepared queries
 - Handle upload failures in the S3 image editor instead of silently continuing
 - Normalize line endings, remove commented-out debug code, add type declarations throughout

# 1.2.5 - 2025-01-12
 - Make Redis Cache compatible to older PHP versions

# 1.2.4 - 2025-01-09
 - Add Revolution Slider support for replacing Media URL
 - Implement CacheInterface for S3 usage
 - Implement workarounds for some functions of image optimizers, because they are too heavy in S3 usage
 
 # 1.2.3 - 2025-01-05
 - Fix S3 thumbnails, when local file is still existing