/*
 * libcurl WebSocket API (libcurl 7.86+, enabled by default since 8.11 and in
 * libcurl-impersonate). Loaded separately so older libcurl builds still load.
 */
typedef void CURL;
typedef int64_t curl_off_t;

struct curl_ws_frame {
    int age;
    int flags;
    curl_off_t offset;
    curl_off_t bytesleft;
    size_t len;
};

int curl_ws_recv(CURL *curl, char *buffer, size_t buflen, size_t *recv, const struct curl_ws_frame **metap);
int curl_ws_send(CURL *curl, const char *buffer, size_t buflen, size_t *sent, curl_off_t fragsize, unsigned int flags);
const struct curl_ws_frame *curl_ws_meta(CURL *curl);
