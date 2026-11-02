#!/bin/sh
# Regenerates n8n/*.json from n8n/src with the SDK bundled in the n8n image.
set -e
docker run --rm -v "$PWD/n8n:/import" --user root --entrypoint sh n8nio/n8n:2.45.7 -c '
  rm -rf /tmp/b && cp -r /import /tmp/b && cd /tmp/b
  ln -s /usr/local/lib/node_modules/n8n/node_modules node_modules
  node build.mjs && cp /tmp/b/*.json /import/'
