/*
 * Core libcurl declarations used by curl-cffi-php through PHP FFI.
 * Every symbol here exists in both stock libcurl and libcurl-impersonate.
 */
typedef void CURL;
typedef void CURLM;
typedef void CURLSH;
typedef int CURLcode;
typedef int CURLMcode;
typedef int CURLSHcode;
typedef int64_t curl_off_t;

struct curl_slist {
    char *data;
    struct curl_slist *next;
};

typedef size_t (*curl_write_callback)(char *ptr, size_t size, size_t nmemb, void *userdata);
typedef size_t (*curl_read_callback)(char *buffer, size_t size, size_t nitems, void *userdata);
typedef int (*curl_xferinfo_callback)(void *clientp, curl_off_t dltotal, curl_off_t dlnow, curl_off_t ultotal, curl_off_t ulnow);
typedef int (*curl_debug_callback)(CURL *handle, int type, char *data, size_t size, void *userptr);

/* Holders: PHP FFI turns a Closure into a C function pointer when it is
 * assigned to a struct field of function pointer type. */
typedef struct { curl_write_callback fn; } cffi_write_holder;
typedef struct { curl_read_callback fn; } cffi_read_holder;
typedef struct { curl_xferinfo_callback fn; } cffi_xferinfo_holder;
typedef struct { curl_debug_callback fn; } cffi_debug_holder;

CURLcode curl_global_init(long flags);
char *curl_version(void);

CURL *curl_easy_init(void);
CURLcode curl_easy_setopt(CURL *curl, int option, ...);
CURLcode curl_easy_perform(CURL *curl);
void curl_easy_cleanup(CURL *curl);
CURLcode curl_easy_getinfo(CURL *curl, int info, ...);
CURL *curl_easy_duphandle(CURL *curl);
void curl_easy_reset(CURL *curl);
const char *curl_easy_strerror(CURLcode code);
CURLcode curl_easy_pause(CURL *handle, int bitmask);

struct curl_slist *curl_slist_append(struct curl_slist *list, const char *data);
void curl_slist_free_all(struct curl_slist *list);

CURLM *curl_multi_init(void);
CURLMcode curl_multi_add_handle(CURLM *multi, CURL *easy);
CURLMcode curl_multi_remove_handle(CURLM *multi, CURL *easy);
CURLMcode curl_multi_cleanup(CURLM *multi);
CURLMcode curl_multi_setopt(CURLM *multi, int option, ...);
const char *curl_multi_strerror(CURLMcode code);
