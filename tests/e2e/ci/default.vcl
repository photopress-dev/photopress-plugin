vcl 4.0;

# Varnish for the end-to-end tests in CI, in front of PHP's built-in server
# as this box's is in front of Apache: purge.vcl is the box's, so a PURGE
# from Proxy Cache Purge is answered as it is there. Nothing is cached; the
# page cache spec checks what is purged and how Varnish answers.

include "purge.vcl";

backend default {
	.host = "127.0.0.1";
	.port = "8080";
	.first_byte_timeout = 120s;
	.between_bytes_timeout = 120s;
}

acl purge {
	"127.0.0.1";
	"::1";
}

sub vcl_recv {
	return (pass);
}
