"""
이미지 증빙 검증 — 제출한 스크린샷에 '표식'(저장 별표·찜 하트 등)이 있는지 템플릿 매칭으로 확인한다.

플레이스 저장(save)·쇼핑 찜(zzim) 미션은 참여자가 실제로 눌렀는지를 텍스트 정답으로 물을 수 없어,
누른 뒤의 화면을 올리게 하고 그 화면에 표식이 있는지로 판정한다(boosting_shop quiz/check_template_*.py 이식).

원본 대비 달라진 점:
  · 템플릿 경로를 인자로 받아 **유형마다 스크립트를 복제하지 않는다**(원본은 save/zzim/wish 로 4벌).
  · 여러 템플릿을 한 번에 받아 **가장 잘 맞는 것**을 고른다(찜은 화면 상태에 따라 아이콘이 두 종류다).
  · 결과를 항상 JSON 한 줄로 낸다 — 실패도 JSON 이라 호출부가 파싱만 하면 된다.

사용법:
  python check-template.py --image=/tmp/a.jpg --templates=/app/t/save.png,/app/t/save2.png [--threshold=0.8]
출력:
  {"ok": true, "probability": 0.93, "template": "save.png", "scale": 1.0, "box": [x, y, w, h]}
  {"ok": false, "probability": 0.41, "reason": "below_threshold"}
"""
import json
import os
import sys

SCALES = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0]


def arg(name, default=''):
    for a in sys.argv[1:]:
        if a.startswith('--' + name + '='):
            return a[len(name) + 3:]
    return default


def out(payload):
    print(json.dumps(payload, ensure_ascii=False))
    sys.exit(0)


def best_match(image, template, threshold):
    """여러 배율로 훑어 가장 높은 매치를 돌려준다 — 기기·해상도마다 아이콘 크기가 다르다."""
    import cv2

    best = None
    for scale in SCALES:
        resized = cv2.resize(template, (0, 0), fx=scale, fy=scale)
        if resized.shape[0] > image.shape[0] or resized.shape[1] > image.shape[1]:
            continue   # 템플릿이 원본보다 크면 매칭할 수 없다
        res = cv2.matchTemplate(image, resized, cv2.TM_CCOEFF_NORMED)
        _, max_val, _, max_loc = cv2.minMaxLoc(res)
        if best is None or max_val > best[0]:
            best = (float(max_val), max_loc, scale, resized.shape[1], resized.shape[0])
    return best


def main():
    image_path = arg('image')
    templates = [t for t in arg('templates').split(',') if t.strip()]
    try:
        threshold = float(arg('threshold', '0.8'))
    except ValueError:
        threshold = 0.8

    if not image_path or not templates:
        out({'ok': False, 'reason': 'usage', 'message': '--image 와 --templates 가 필요합니다'})

    try:
        import cv2
    except ImportError:
        out({'ok': False, 'reason': 'opencv_missing', 'message': 'opencv-python 이 설치되어 있지 않습니다'})

    image = cv2.imread(image_path, cv2.IMREAD_COLOR)
    if image is None:
        out({'ok': False, 'reason': 'image_unreadable', 'message': '제출 이미지를 읽지 못했습니다'})

    top = None
    loaded = 0
    for path in templates:
        template = cv2.imread(path, cv2.IMREAD_COLOR)
        if template is None:
            continue   # 템플릿 하나가 없어도 나머지로 판정한다
        loaded += 1
        found = best_match(image, template, threshold)
        if found and (top is None or found[0] > top[0][0]):
            top = (found, os.path.basename(path))

    if top is None:
        # 템플릿을 못 읽은 것과, 읽었지만 제출 이미지가 표식보다 작아 댈 수 없는 것은 다른 상황이다
        if loaded == 0:
            out({'ok': False, 'reason': 'template_unreadable', 'message': '템플릿 이미지를 읽지 못했습니다'})
        out({'ok': False, 'probability': 0.0, 'reason': 'image_too_small', 'message': '제출 이미지가 표식보다 작습니다'})

    (prob, loc, scale, w, h), name = top
    if prob < threshold:
        out({'ok': False, 'probability': round(prob, 4), 'reason': 'below_threshold', 'template': name})

    out({
        'ok': True,
        'probability': round(prob, 4),
        'template': name,
        'scale': scale,
        'box': [int(loc[0]), int(loc[1]), int(w), int(h)],
    })


if __name__ == '__main__':
    main()
