#!/usr/bin/env python3
# kappstore用の商品画像。実画面(承認キューのスクリーンショット)を主役にする。
import sys
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 675
SHOT = sys.argv[1] if len(sys.argv) > 1 else "/tmp/kca_product_shot.png"
OUT = "outputs/kcrmagent_product.png"
BLACK = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
BOLD = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"

img = Image.new("RGB", (W, H), "#f2f7f8")
d = ImageDraw.Draw(img)
for x in range(W):  # 上帯
    t = x / W
    r = int(0x1b + (0x2f - 0x1b) * t); g = int(0x6d + (0x8f - 0x6d) * t); b = int(0x8c + (0x6f - 0x8c) * t)
    d.line([(x, 0), (x, 9)], fill=(r, g, b))

f_t = ImageFont.truetype(BLACK, 50)
f_t2 = ImageFont.truetype(BLACK, 34)
f_s = ImageFont.truetype(BOLD, 24)
f_b = ImageFont.truetype(BOLD, 21)
f_n = ImageFont.truetype(BOLD, 17)

# 右: 実画面(承認キュー部分を切り出す)
shot = Image.open(SHOT).convert("RGB").crop((150, 340, 1130, 800))
sw = 620
sh = int(shot.height * sw / shot.width)
shot = shot.resize((sw, sh), Image.LANCZOS)
fx, fy = W - sw - 36, 150
d.rounded_rectangle([fx - 10, fy - 10, fx + sw + 10, fy + sh + 10], radius=18, fill="#14262e")
img.paste(shot, (fx, fy))
d.text((fx + 4, fy + sh + 22), "実画面: AIが起票した下書きを、人が1タップで承認", font=f_n, fill="#5a6c76")

# 左: コピー
lx = 44
d.text((lx, 48), "入力ゼロCRM", font=f_t, fill="#14262e")
d.text((lx, 112), "Kurage CRM Agent", font=f_t2, fill="#1b6d8c")
d.text((lx, 175), "日報を投げるだけ。\n起票はAI、確定はあなた。", font=f_s, fill="#2a3b44")

feats = [
    "日報の文章から会社・商談・活動を起票",
    "AIは下書きまで。反映は人の承認だけ",
    "金額・ステージ・日付はコードが検証",
    "かんばん・会社台帳・監査ログつき",
    "メール・チャット連携のAPI入口",
    "PHP+SQLite。レンタルサーバーで動く",
]
y = 246
for f in feats:
    d.ellipse([lx, y + 7, lx + 12, y + 19], fill="#1b6d8c")
    d.text((lx + 24, y), f, font=f_b, fill="#33434c")
    y += 44

d.rounded_rectangle([lx, y + 16, lx + 420, y + 74], radius=12, fill="#1b6d8c")
d.text((lx + 22, y + 30), "買い切り 55,000円(税込) / 月額なし", font=f_s, fill="#ffffff")

img.save(OUT)
print("saved:", OUT, img.size)
