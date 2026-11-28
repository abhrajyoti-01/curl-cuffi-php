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
typedef int curl_socket_t; /* SOCKET (64-bit) on Windows: Library swaps this line */

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
/* Leading fields of curl_version_info_data, all present since CURLVERSION_FIRST */
typedef struct {
    int age;
    const char *version;
    unsigned int version_num;
    const char *host;
    int features;
    const char *ssl_version;
    long ssl_version_num;
    const char *libz_version;
    const char *const *protocols;
} curl_version_info_data;
curl_version_info_data *curl_version_info(int age);

CURL *curl_easy_init(void);
CURLcode curl_easy_setopt(CURL *curl, int option, ...);
CURLcode curl_easy_perform(CURL *curl);
void curl_easy_cleanup(CURL *curl);
CURLcode curl_easy_getinfo(CURL *curl, int info, ...);
CURL *curl_easy_duphandle(CURL *curl);
void curl_easy_reset(CURL *curl);
const char *curl_easy_strerror(CURLcode code);
CURLcode curl_easy_pause(CURL *handle, int bitmask);
CURLcode curl_easy_upkeep(CURL *curl);

struct curl_slist *curl_slist_append(struct curl_slist *list, const char *data);
void curl_slist_free_all(struct curl_slist *list);

CURLM *curl_multi_init(void);
CURLMcode curl_multi_add_handle(CURLM *multi, CURL *easy);
CURLMcode curl_multi_remove_handle(CURLM *multi, CURL *easy);
CURLMcode curl_multi_cleanup(CURLM *multi);
CURLMcode curl_multi_setopt(CURLM *multi, int option, ...);
const char *curl_multi_strerror(CURLMcode code);

/* multi interface (streaming responses, AsyncSession) */
typedef struct {
    int msg; /* CURLMSG_DONE = 1 */
    CURL *easy_handle;
    union {
        void *whatever;
        CURLcode result;
    } data;
} CURLMsg;
/* extra sockets for curl_multi_poll(); events: CURL_WAIT_POLLIN 1, POLLPRI 2, POLLOUT 4 */
struct curl_waitfd {
    curl_socket_t fd;
    short events;
    short revents;
};
CURLMcode curl_multi_perform(CURLM *multi, int *running_handles);
CURLMcode curl_multi_poll(CURLM *multi, void *extra_fds, unsigned int extra_nfds, int timeout_ms, int *numfds);
CURLMcode curl_multi_wakeup(CURLM *multi);
CURLMsg *curl_multi_info_read(CURLM *multi, int *msgs_in_queue);

/* mime (multipart/form-data uploads) */
typedef void curl_mime;
typedef void curl_mimepart;
curl_mime *curl_mime_init(CURL *easy);
void curl_mime_free(curl_mime *mime);
curl_mimepart *curl_mime_addpart(curl_mime *mime);
CURLcode curl_mime_name(curl_mimepart *part, const char *name);
CURLcode curl_mime_filename(curl_mimepart *part, const char *filename);
CURLcode curl_mime_type(curl_mimepart *part, const char *mimetype);
CURLcode curl_mime_data(curl_mimepart *part, const char *data, size_t datasize);
CURLcode curl_mime_filedata(curl_mimepart *part, const char *filename);
