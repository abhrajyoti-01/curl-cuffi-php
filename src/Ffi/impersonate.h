/*
 * Symbols that only libcurl-impersonate (and newer libcurl) export.
 * Loaded separately so the library still loads against a stock libcurl,
 * which is useful for tests, and reports clearly what is missing.
 */
typedef void CURL;
typedef int CURLcode;

CURLcode curl_easy_impersonate(CURL *curl, const char *target, int default_headers);
