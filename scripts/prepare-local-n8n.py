"""Writes credentials and a wired copy of the workflow to .local/n8n for the local n8n container."""
import json
import os

env = dict(
    line.strip().split("=", 1)
    for line in open(".env", encoding="utf8")
    if "=" in line and not line.startswith("#")
)

credentials = [
    {"id": "hrhmac", "name": "Health Report HMAC", "type": "crypto", "data": {"hmacSecret": env["SHR_HMAC_SECRET"]}},
    {
        "id": "hrscanner",
        "name": "Health Report Scanner Key",
        "type": "httpHeaderAuth",
        "data": {"name": "X-Api-Key", "value": env["SCANNER_API_KEY"]},
    },
    # Without a Google key this sends a harmless quotaUser value, so PageSpeed runs keyless.
    {
        "id": "hrpsi",
        "name": "Health Report PageSpeed Key",
        "type": "httpQueryAuth",
        "data": {"name": "quotaUser", "value": env.get("PAGESPEED_API_KEY") or "shr-local"},
    },
]
if env.get("PAGESPEED_API_KEY"):
    credentials[2]["data"]["name"] = "key"

wiring = {
    "Compute Job Signature": ("crypto", 0),
    "Sign Result": ("crypto", 0),
    "Create Scanner Job": ("httpHeaderAuth", 1),
    "Get Scanner Result": ("httpHeaderAuth", 1),
    "PageSpeed Insights": ("httpQueryAuth", 2),
}

workflow = json.load(open("n8n/health-report-scan-pipeline.json", encoding="utf8"))
workflow["id"] = "hrScanPipelineLocal"
workflow["active"] = False
for node in workflow["nodes"]:
    if node["name"] in wiring:
        kind, index = wiring[node["name"]]
        node["credentials"] = {kind: {"id": credentials[index]["id"], "name": credentials[index]["name"]}}

os.makedirs(".local/n8n", exist_ok=True)
json.dump(credentials, open(".local/n8n/credentials.json", "w", encoding="utf8"))
json.dump(workflow, open(".local/n8n/pipeline.json", "w", encoding="utf8"))
print("wrote .local/n8n")
