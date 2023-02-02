<p align="center">

<img  src="https://mk0abtastybwtpirqi5t.kinstacdn.com/wp-content/uploads/picture-solutions-persona-product-flagship.jpg"  width="211"  height="182"  alt="flagship-varnish-strategy"  />

</p>

# Flagship Strategy for varnish

### Overview

This repository explains how to set a caching strategy for a Flagship usage with Varnish and PHP as example.

### General concepts

Content caching greatly increases performance and reduces load on the infrastructure, it has an immediate impact on the experimentation & personalization possibilities of a web application. 

Following our module implementation for content caching in varnish [Link](https://github.com/flagship-io/flagship-varnish-module), one of the downsides was that the visitor ID and context were computed at cache level and we don't have access to any high level or custom information about the visitor such as database informations. 

That lead us to think of a strategy and workflow in order to solve this issue and provide an all-in solution to content caching.

### How it works ?

The cache server run alongside a lightweight backend that synchronizes with your Flagship configuration to provide feature flagging & experimentation abilities to the cache server.

The lightweight backend can implement our SDKs or the Decision API with your custom visitor information coming from the databases. For more explanation check our documentation [Documentation](https://docs.developers.flagship.io/docs/solution-strategy).

### Implementation

Since the strategy can be adopted by any web server or HTTP accelerator that manages content caching, the implementation can differ from provider to another, for instance in this example we used varnish configuration (vcl) to reverse proxy the HTTP request coming from the client and check if the cookies **fs-experiences** exists, if it does we serve the server directly, if not we send a HEAD request to the dedicated Flagship server to retrieve the information related to the visitor and create the cookie. **Note:** that the content of the cookie will be served as a cache key for the varnish caching table.

#### Dockerfile

```Dockerfile
FROM php:8.1-apache

COPY . /var/www/html/

WORKDIR /var/www/html/

RUN apt-get update
RUN apt-get install -y libzip-dev unzip
RUN docker-php-ext-install zip

RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer

EXPOSE 80

RUN composer install

```

#### docker-compose.yml

```yml
version: "3"
services:
  varnish:
    image: varnish:stable
    container_name: varnish
    volumes:
      - "./config/default.vcl:/etc/varnish/default.vcl"
    ports:
      - "80:80"
    tmpfs:
      - /var/lib/varnish:exec
    environment:
      - VARNISH_SIZE=2G
    command: "-p default_keep=300"
    depends_on:
      - "php"
  php:
    build:
      context: .
      dockerfile: ./Dockerfile
    image: github.com/flagship-io/flagship-varnish-strategy
    environment:
      FS_ENV_ID:
      FS_API_KEY:
    container_name: php
    ports:
      - "8080:80"
```

Fill the FS_ENV_ID and FS_API_KEY with your own credentials, and run `docker compose up -d`

### Running

#### Sample varnish configuration

```VCL
vcl 4.1;
import std;
import directors;
backend server1 { # Define one backend
 .host = "php"; # IP or Hostname of backend
 .port = "80"; # Port Apache or whatever is listening
 .max_connections = 10; # That's it
 .probe = {
    # We prefer to only do a HEAD
  .request =
   "HEAD /ping.php HTTP/1.1"
   "Host: localhost"
   "User-Agent: Varnish; Health Check"
   "Connection: close";
  .interval = 200s; # check the health of each backend every X seconds
  .timeout = 1s; # timing out after 1 second.
  # If 3 out of the last 5 polls succeeded the backend is considered healthy, otherwise it will be marked as sick
  .window = 5;
  .threshold = 3;
  }
 .first_byte_timeout     = 300s;   # How long to wait before we receive a first byte from our backend?
 .connect_timeout        = 5s;     # How long to wait for a backend connection?
 .between_bytes_timeout  = 2s;     # How long to wait between bytes received from our backend?
}
acl purge {
# ACL we'll use later to allow purges
 "localhost";
 "127.0.0.1";
 "::1";
}

sub vcl_init {
# Called when VCL is loaded, before any requests pass through it. Typically used to initialize VMODs.
 new vdir = directors.round_robin();
 vdir.add_backend(server1);
 # vdir.add_backend(serverN);
}

sub vcl_recv {

std.log("FS vcl_recv");
 set req.backend_hint = vdir.backend(); # send all traffic to the vdir director

 if (req.restarts == 0) {
  if (req.http.X-Forwarded-For) { # set or append the client.ip to X-Forwarded-For header
   set req.http.X-Forwarded-For = req.http.X-Forwarded-For + ", " + client.ip;
  } else {
   set req.http.X-Forwarded-For = client.ip;
  }
 }

 # Normalize the header, remove the port (in case you're testing this on various TCP ports)
 set req.http.Host = regsub(req.http.Host, ":[0-9]+", "");

 # Normalize the query arguments
 set req.url = std.querysort(req.url);

 if (req.method == "REFRESH") {
 if (!client.ip ~ purge) { # purge is the ACL defined at the begining
  # Not from an allowed IP? Then die with an error.
  return (synth(405, "This IP is not allowed to send REFRESH requests."));
 }
 # If you got this stage (and didn't error out above), purge the cached result
   set req.method = "GET";
   set req.hash_always_miss = true;
 }

 if (req.method == "BAN") {
 # See https://www.varnish-software.com/static/book/Cache_invalidation.html#smart-bans
  if (!client.ip ~ purge) { # purge is the ACL defined at the begining
   # Not from an allowed IP? Then die with an error.
   return (synth(405, "This IP is not allowed to send BAN requests."));
  }
  # If you got this stage (and didn't error out above), purge the cached result

                ban("obj.http.x-url ~ " + req.http.x-ban-url +
                    " && obj.http.x-host ~ " + req.http.x-ban-host);
                return (synth(200, "Banned"));

        }


 # Only deal with "normal" types
 if (req.method != "GET" &&
   req.method != "HEAD" &&
   req.method != "PUT" &&
   req.method != "POST" &&
   req.method != "TRACE" &&
   req.method != "OPTIONS" &&
   req.method != "PATCH" &&
   req.method != "DELETE") {
  /* Non-RFC2616 or CONNECT which is weird. */
  return (pipe);
 }



 # Implementing websocket support (https://www.varnish-cache.org/docs/4.0/users-guide/vcl-example-websockets.html)
 if (req.http.Upgrade ~ "(?i)websocket") {
  return (pipe);
 }

 # Some generic URL manipulation, useful for all templates that follow
 # First remove the Google Analytics added parameters, useless for our backend
 if (req.url ~ "(\?|&)(utm_source|utm_medium|utm_campaign|utm_content|gclid|cx|ie|cof|siteurl)=") {
  set req.url = regsuball(req.url, "&(utm_source|utm_medium|utm_campaign|utm_content|gclid|cx|ie|cof|siteurl)=([A-z0-9_\-\.%25]+)", "");
  set req.url = regsuball(req.url, "\?(utm_source|utm_medium|utm_campaign|utm_content|gclid|cx|ie|cof|siteurl)=([A-z0-9_\-\.%25]+)", "?");
  set req.url = regsub(req.url, "\?&", "?");
  set req.url = regsub(req.url, "\?$", "");
 }

 # Strip hash, server doesn't need it.
 if (req.url ~ "\#") {
  set req.url = regsub(req.url, "\#.*$", "");
 }

 # Strip a trailing ? if it exists
 if (req.url ~ "\?$") {
  set req.url = regsub(req.url, "\?$", "");
 }

 # Are there cookies left with only spaces or that are empty?
 if (req.http.cookie ~ "^\s*$") {
  unset req.http.cookie;
 }

 # FS_recv
 # Method GET - The visitor main interaction and very first.
 if(req.method == "GET") {

  # Known FS (Cookie)
   # Parse Cookie
   # Header exist

  if(req.http.Cookie ~ "fs-experiences"){
   std.log("PARSE COOKIES");
   set req.http.x-fs-experiences-cookie = regsub(req.http.Cookie, "(.*)(fs-experiences=)([^;]*)", "\3"); # Trunk Cookie to only fs_experiences cookie content
   if(req.http.x-fs-experiences-cookie ~ "^(.+)@([A-Fa-f0-9]{64})") {
    std.log("FS KNOWN");
    set req.http.x-fs-visitor = regsub(req.http.x-fs-experiences-cookie, "^(.+)@([A-Fa-f0-9]{64})", "\1"); # Capture VisitorID
    set req.http.x-fs-experiences = regsub(req.http.x-fs-experiences-cookie, "^(.+)@([A-Fa-f0-9]{64})", "\2"); # Capture [CacheKey]
    std.log("HEADER AVAILABLE");
   }
  }

  # Unknown FS
  # Pass then restart
  if(!req.http.x-fs-experiences) {
   std.log("FS UNKNOWN");
   if(req.restarts == 0) { # security, set restart N
    std.log("READY FOR RESTART");
    # Giving the signal
    set req.http.x-fs-take-decision = "0";
    return (pass); # See you in #2 OR RESTART ?
   }
  }
 }
 # END FS_recv

 return (hash);
}

sub vcl_hash {

 std.log("FS vcl_hash");

 if (req.http.host) {
  hash_data(req.http.host);
 } else {
  hash_data(server.ip);
 }

 hash_data(req.url);
 # FS_hash
 if(req.http.x-fs-experiences){
  std.log("LOOK IN CACHE w/ FS");
  hash_data(req.http.x-fs-experiences);
 }
 # END FS_hash

 return (lookup);
}


sub vcl_backend_fetch {
  std.log("FS vcl_backend_fetch");
    if (bereq.method == "GET" || bereq.method == "HEAD") {
        unset bereq.body;
    }
 # FS_be_fetch
 if(bereq.http.x-fs-take-decision){
  if(bereq.http.x-fs-take-decision == "0") {
   set bereq.method = "HEAD";
  }
  if(bereq.http.x-fs-take-decision == "1") {
   set bereq.method = "GET";
  }
  unset bereq.http.x-fs-take-decision;
 }
 # END FS_be_fetch

    return (fetch);
}

sub vcl_deliver {
  std.log("FS vcl_deliver");
 # DEBUG Only
 set resp.http.x-restart = req.restarts;

 if(req.http.x-fs-take-decision){
  if(req.http.x-fs-take-decision == "0" && req.restarts == 0) {
   set req.method = "GET";
   set req.http.x-fs-visitor = resp.http.x-fs-visitor;
   set req.http.x-fs-experiences = resp.http.x-fs-experiences;
   set req.http.x-fs-take-decision = "1";
   std.log("RESTART HEAD");
   return (restart);
  }
  if((req.http.x-fs-take-decision == "1" && req.http.x-fs-experiences)) {
   std.log("BECOMING KNOWN FS - Set Cookie");
   if (resp.http.Set-Cookie) {
    set resp.http.Set-Cookie = resp.http.Set-Cookie + "fs-experiences="+req.http.x-fs-visitor+"@"+req.http.x-fs-experiences+"; path=/; domain="+req.http.Host;
   } else {
    set resp.http.Set-Cookie = "fs-experiences="+req.http.x-fs-visitor+"@"+req.http.x-fs-experiences+"; path=/; domain="+req.http.Host;
   }
  }
 }

 if (obj.hits > 0) {
  set resp.http.X-Cache = "HIT";
 } else {
  set resp.http.X-Cache = "MISS";
 }

 return (deliver);
}
```

## Reference

- [Server-side Content caching](https://docs.developers.flagship.io/docs/cache-layer)
- [varnish guide](https://varnish-cache.org/docs/trunk/index.html)

## License

[Apache License.](https://github.com/flagship-io/flagship-varnish-strategy/blob/master/LICENSE)
