vcl 4.1;

import std;
backend default {
    .host = "php";
    .port = "80";
    .max_connections = 100;
    .connect_timeout = 5s;
    .first_byte_timeout = 90s;
    .between_bytes_timeout = 2s;
}

sub vcl_recv {
	set req.url = std.querysort(req.url);

	if (req.method != "GET" &&
			req.method != "HEAD" &&
			req.method != "PUT" &&
			req.method != "POST" &&
			req.method != "TRACE" &&
			req.method != "OPTIONS" &&
			req.method != "PATCH" &&
			req.method != "DELETE") {
		return (pipe);
	}

	if (req.http.cookie ~ "^\s*$") {
		unset req.http.cookie;
	}

	# Flagship Minimal Flow
	# This is the minimal config flow for Flagship x Varnish VCL
	# Want more details ? Check our the commented version on ./default.vcl 
	if(req.method == "GET" && req.http.Cookie !~ "fs-experiences=ignore-me@optout") {
		if(req.http.Cookie ~ "fs-experiences"){
			set req.http.x-fs-experiences = regsub(req.http.Cookie, "(.*?)(fs-experiences=)([^;]*)(.*)$", "\3");
			if(req.http.x-fs-experiences == regsub(req.http.x-fs-experiences, "([aA-zZ\d\-]+)[@|%40](([0-9a-v]{20}+[:|%3A][0-9a-v]{20}+[\||%7C]?)+)", "\2")) {
				set req.http.x-fs-visitor = "ignore-me";
				set req.http.x-fs-experiences = "optout";
			} else {
				set req.http.x-fs-visitor = regsub(req.http.x-fs-experiences, "([aA-zZ\d\-]+)[@|%40](([0-9a-v]{20}+[:|%3A][0-9a-v]{20}+[\||%7C]?)+)", "\1"); # Capture VisitorID
				set req.http.x-fs-experiences = regsub(req.http.x-fs-experiences, "([aA-zZ\d\-]+)[@|%40](([0-9a-v]{20}+[:|%3A][0-9a-v]{20}+[\||%7C]?)+)", "\2"); # Capture [CacheKey]
			}
		}
		if(!req.http.x-fs-experiences) {
			if(req.restarts == 0) {
				set req.http.x-fs-take-decision = "0";
				return (pass);
			}
		}

		if(req.http.x-fs-take-decision || req.http.x-fs-experiences){
			if(req.http.x-fs-experiences == "optout"){
				unset req.http.x-fs-visitor;
				unset req.http.x-fs-experiences;
			}
		}
		
	}

	return (hash);
}

sub vcl_hash {
	if (req.http.host) {
		hash_data(req.http.host);
	} else {
		hash_data(server.ip);
	}

	hash_data(req.url);

	if(req.http.x-fs-experiences){
		hash_data(req.http.x-fs-experiences);
	}

	return (lookup);
}


sub vcl_backend_fetch {
    if (bereq.method == "GET" || bereq.method == "HEAD") {
        unset bereq.body;
    }
	if(bereq.http.x-fs-take-decision){
		if(bereq.http.x-fs-take-decision == "0") {
			set bereq.method = "HEAD";
		}
		if(bereq.http.x-fs-take-decision == "1") {
			set bereq.method = "GET";
		}
		unset bereq.http.x-fs-take-decision;
	}
	
    return (fetch);
}

sub vcl_deliver {
	if(req.http.x-fs-take-decision){
		if(req.http.x-fs-take-decision == "0" && req.restarts == 0) {
			set req.method = "GET";
			set req.http.x-fs-visitor = resp.http.x-fs-visitor;
			set req.http.x-fs-experiences = resp.http.x-fs-experiences;
			set req.http.x-fs-take-decision = "1";
			return (restart);
		} 
		if((req.http.x-fs-take-decision == "1" && req.http.x-fs-experiences)) {
			if (resp.http.Set-Cookie) {
				set resp.http.Set-Cookie = resp.http.Set-Cookie + "fs-experiences="+req.http.x-fs-visitor+"@"+req.http.x-fs-experiences+"; path=/; domain="+req.http.Host;
			} else {
				set resp.http.Set-Cookie = "fs-experiences="+req.http.x-fs-visitor+"@"+req.http.x-fs-experiences+"; path=/; domain="+req.http.Host;
			}
		}
	}

	return (deliver);
}