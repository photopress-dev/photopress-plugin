# purge.vcl -- Cache Purge Library for Varnish
#
# Copyright (C) 2013 DreamHost (New Dream Network, LLC)
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
# Changed on this box: bans match the X-Req-* headers stamped on every
# object below, never req.*, which the ban lurker cannot evaluate; and a
# PURGE is answered once banned, not looked up and fetched from the backend.

# A PURGE becomes a ban, by one of three methods.

# Regex: the request URL is a regular expression.
sub purge_regex {
	ban("obj.http.X-Req-URL ~ " + req.url + " && obj.http.X-Req-Host == " + req.http.host);
}

# Exact: the request URL, query string included.
sub purge_exact {
	ban("obj.http.X-Req-URL == " + req.url + " && obj.http.X-Req-Host == " + req.http.host);
}

# Page (the default): the request URL, whatever the query string.
sub purge_page {
	set req.url = regsub(req.url, "\?.*$", "");
	ban("obj.http.X-Req-URL-Base == " + req.url + " && obj.http.X-Req-Host == " + req.http.host);
}

# The X-Purge-Method header, "regex" or "exact" (any case), chooses the
# method; any other value means page. Without the header the URL is read:
# a regex if it looks like one, exact if it has a query string, else page.

sub vcl_recv {
	if (req.method == "PURGE") {
		if (client.ip !~ purge) {
			return (synth(405, "Not allowed"));
		}

		if (req.http.X-Purge-Method) {
			if (req.http.X-Purge-Method ~ "(?i)regex") {
				call purge_regex;
			} elsif (req.http.X-Purge-Method ~ "(?i)exact") {
				call purge_exact;
			} else {
				call purge_page;
			}
		} elsif (req.url ~ "\.\*" || req.url ~ "^\^" || req.url ~ "\$$" || req.url ~ "\\[.?*+^$|()]") {
			call purge_regex;
		} elsif (req.url ~ "\?") {
			call purge_exact;
		} else {
			call purge_page;
		}

		# Answered here. Looking it up instead had Varnish fetch the purged
		# URL from WordPress -- for a regex, the literal pattern, a 404 --
		# while the purging request waited, about a second a URL.
		return (synth(200, "Purged"));
	}
}

# The headers bans match, on every object; clients never see them.
sub vcl_backend_response {
	set beresp.http.X-Req-Host = bereq.http.host;
	set beresp.http.X-Req-URL = bereq.url;
	set beresp.http.X-Req-URL-Base = regsub(bereq.url, "\?.*$", "");
}

sub vcl_deliver {
	unset resp.http.X-Req-Host;
	unset resp.http.X-Req-URL;
	unset resp.http.X-Req-URL-Base;
}
