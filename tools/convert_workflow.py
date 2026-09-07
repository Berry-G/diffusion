"""ComfyUI 워크플로우(UI 포맷) -> API 포맷 변환기.

ComfyUI 화면에서 워크플로우를 고쳤다면 이 스크립트를 다시 돌려
workflow_api.json 을 갱신하세요. ComfyUI 가 켜져 있어야 합니다
(실제 노드 스펙을 /object_info 에서 받아 위젯 순서를 맞추기 때문입니다).

    G:\\comfy\\.venv\\Scripts\\python.exe tools\\convert_workflow.py

노드 번호가 바뀌었다면 config.php 의 nodes 매핑도 함께 고쳐야 합니다.
"""
import json
import os
import sys
import urllib.request

COMFY = os.environ.get("COMFY_URL", "http://127.0.0.1:8000")

# 원본 워크플로우(UI 포맷)의 경로. 기기마다 다르고 파일명 자체가
# 이 저장소에 있을 이유가 없어서 밖으로 뺐다. 우선순위는
#   1) 환경변수 WORKFLOW_SRC
#   2) tools/workflow_src.local.txt 의 첫 줄 (gitignore 됨)
#   3) 아래 기본값
_LOCAL_SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                          "workflow_src.local.txt")


def _default_src():
    try:
        with open(_LOCAL_SRC, encoding="utf-8") as fh:
            line = fh.readline().strip()
            if line:
                return line
    except OSError:
        pass
    return r"G:\comfy\user\default\workflows\workflow.json"


SRC = os.environ.get("WORKFLOW_SRC") or _default_src()
DST = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                   "workflow_api.json")

# 프론트엔드에만 존재하는 노드 — API 포맷에 실리지 않고 그 값이 인라인된다.
VIRTUAL = {"PrimitiveNode", "Note", "MarkdownNote", "Reroute", "Reroute (rgthree)"}
CONTROL_VALUES = {"fixed", "randomize", "increment", "decrement"}

# 웹 폼에서는 쓰지 않는 노드 (예: 같은 이미지를 한 번 더 저장하는 SaveImage)
DROP_NODES = {120}


def fetch_object_info():
    try:
        with urllib.request.urlopen(COMFY + "/object_info", timeout=60) as r:
            return json.load(r)
    except Exception as e:
        sys.exit(f"ComfyUI({COMFY}) 에서 노드 정보를 받지 못했습니다: {e}\n"
                 "start-comfy.bat 으로 서버를 먼저 켜세요.")


def is_widget_input(spec):
    """input spec 이 위젯(값 입력)인지 판정."""
    t = spec[0]
    if isinstance(t, list):
        return True  # 구형 COMBO — 선택지 배열이 타입 자리에 그대로 온다
    return t in ("INT", "FLOAT", "STRING", "BOOLEAN", "COMBO")


def widget_inputs(oi, class_type):
    """(이름, spec) 목록을 input_order 순서로 반환."""
    d = oi.get(class_type)
    if d is None:
        return []
    inp = d["input"]
    order = d.get("input_order") or {}
    out = []
    for section in ("required", "optional"):
        names = order.get(section) or list((inp.get(section) or {}).keys())
        for name in names:
            spec = (inp.get(section) or {}).get(name)
            if spec is not None:
                out.append((name, spec))
    return out


def main():
    oi = fetch_object_info()
    wf = json.load(open(SRC, encoding="utf-8"))
    nodes = {n["id"]: n for n in wf["nodes"]}
    links = {l[0]: l for l in wf.get("links", [])}
    warnings = []

    def resolve_link(link_id):
        l = links.get(link_id)
        if l is None:
            return None
        origin_id, origin_slot = l[1], l[2]
        origin = nodes.get(origin_id)
        if origin is None:
            return None
        if origin["type"] in VIRTUAL:
            # PrimitiveNode 는 자기 값을 대상 위젯에 그대로 넣어 준다.
            return origin.get("widgets_values", [None])[0]
        if origin.get("mode") in (2, 4) or origin_id in DROP_NODES:
            return None
        return [str(origin_id), origin_slot]

    api = {}
    for nid, n in nodes.items():
        ctype = n["type"]
        # mode 2=bypass, 4=mute — 실행 그래프에서 빠진다
        if ctype in VIRTUAL or n.get("mode") in (2, 4) or nid in DROP_NODES:
            continue
        if ctype not in oi:
            warnings.append(f"[{nid}] {ctype}: 설치되지 않은 노드 — 건너뜀")
            continue

        inputs = {}
        vals = list(n.get("widgets_values") or [])

        if ctype == "Power Lora Loader (rgthree)":
            # 동적 위젯이라 object_info 로는 순서를 알 수 없다.
            # 백엔드(load_loras)는 lora_N 키의 {on, lora, strength, strengthTwo} 만 본다.
            idx = 0
            for v in vals:
                if isinstance(v, dict) and "lora" in v and "on" in v:
                    idx += 1
                    inputs[f"lora_{idx}"] = v
            inputs["PowerLoraLoaderHeaderWidget"] = {"type": "PowerLoraLoaderHeaderWidget"}
        else:
            vi = 0
            for name, spec in widget_inputs(oi, ctype):
                if not is_widget_input(spec):
                    continue
                if vi >= len(vals):
                    break
                inputs[name] = vals[vi]
                vi += 1
                # seed 뒤에 붙는 control_after_generate 위젯은 실행값이 아니다.
                opts = spec[1] if len(spec) > 1 and isinstance(spec[1], dict) else {}
                if (opts.get("control_after_generate") or spec[0] == "INT") \
                        and vi < len(vals) and vals[vi] in CONTROL_VALUES:
                    vi += 1

        # 연결된 링크는 위젯값보다 우선한다.
        for i in n.get("inputs") or []:
            if i.get("link") is None:
                continue
            r = resolve_link(i["link"])
            if r is not None:
                inputs[i["name"]] = r

        api[str(nid)] = {"class_type": ctype, "inputs": inputs,
                         "_meta": {"title": n.get("title") or ctype}}

    # 프롬프트는 요청마다 api.php 가 채우므로 템플릿에서는 비워 둔다.
    for node in api.values():
        if node["class_type"] == "ImpactWildcardEncode":
            node["inputs"]["wildcard_text"] = ""
            node["inputs"]["populated_text"] = ""
            node["inputs"]["mode"] = "fixed"
            # 와일드카드 캐시 상태에 따라 선택지가 달라져 검증에 걸리는 것을 막는다.
            node["inputs"]["Select to add Wildcard"] = "Select the Wildcard to add to the text"
        elif node["class_type"] == "ToDetailerPipe":
            node["inputs"]["Select to add Wildcard"] = "Select the Wildcard to add to the text"

    json.dump(api, open(DST, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"노드 {len(api)}개 -> {DST}")
    for w in warnings:
        print("  경고:", w)


if __name__ == "__main__":
    main()
