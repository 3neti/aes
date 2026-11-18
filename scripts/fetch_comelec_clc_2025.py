import concurrent.futures
import datetime
import hashlib
import json
import os
import pathlib
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

catalog = json.load(open("resources/election/clc/2025-national-source-catalog.json"))
root = pathlib.Path("storage/app/comelec-clc-2025/raw")
root.mkdir(parents=True, exist_ok=True)


def download(source):
    url = source["url"]
    parsed = urllib.parse.urlparse(url)
    if parsed.hostname not in {"www.comelec.gov.ph", "comelec.gov.ph"} or not parsed.path.startswith(
        "/php-tpls-attachments/2025NLE/COC_2025NLE/CLC2025_"
    ):
        raise ValueError("Unexpected PDF source: " + url)
    destination = root / source["region"] / pathlib.Path(parsed.path).name
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.is_file():
        data = destination.read_bytes()
        if data.startswith(b"%PDF-"):
            return {**source, "path": str(destination), "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}

    for attempt in range(3):
        temporary = None
        try:
            request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0", "Referer": "https://www.comelec.gov.ph/"})
            with urllib.request.urlopen(request, timeout=40) as response:
                if response.status != 200:
                    raise ValueError("HTTP status " + str(response.status))
                with tempfile.NamedTemporaryFile(dir=destination.parent, prefix=".download-", delete=False) as output:
                    temporary = pathlib.Path(output.name)
                    digest = hashlib.sha256()
                    size = 0
                    first = True
                    while chunk := response.read(65536):
                        if first and not chunk.startswith(b"%PDF-"):
                            raise ValueError("Response is not a PDF")
                        first = False
                        size += len(chunk)
                        if size > 20_000_000:
                            raise ValueError("PDF exceeds 20 MB")
                        output.write(chunk)
                        digest.update(chunk)
            os.replace(temporary, destination)
            return {**source, "path": str(destination), "bytes": size, "sha256": digest.hexdigest()}
        except Exception:
            if temporary is not None:
                temporary.unlink(missing_ok=True)
            if attempt == 2:
                raise
            time.sleep(1 + attempt * 2)


results = []
failures = []
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    futures = {pool.submit(download, source): source for source in catalog["sources"]}
    for future in concurrent.futures.as_completed(futures):
        try:
            results.append(future.result())
        except Exception as exception:
            failures.append({"url": futures[future]["url"], "error": str(exception)})
        finished = len(results) + len(failures)
        if finished % 100 == 0 or finished == len(futures):
            print(f"{finished}/{len(futures)} complete, {len(failures)} failures", flush=True)

results.sort(key=lambda result: (result["region"], result["url"]))
manifest = {
    "schema_version": "comelec-2025-clc-download-manifest-1",
    "source_catalog_sha256": hashlib.sha256(open("resources/election/clc/2025-national-source-catalog.json", "rb").read()).hexdigest(),
    "retrieved_at_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "source_count": len(catalog["sources"]),
    "downloaded_count": len(results),
    "failure_count": len(failures),
    "total_bytes": sum(result["bytes"] for result in results),
    "files": results,
    "failures": failures,
}
output = root.parent / "download-manifest.json"
output.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
print("manifest", output, flush=True)
if failures:
    raise SystemExit(1)
