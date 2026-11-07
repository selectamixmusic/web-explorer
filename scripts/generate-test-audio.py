#!/usr/bin/env python3
"""Synthesises test tunes for the Selectamix player.

Each tune is a steady four-to-the-floor pattern at a known BPM with its own
bass note and a chord change every eight bars, so tempo matching, cue
alignment and blends are audible. Bar 1 beat 1 sits at FIRST_DOWNBEAT.

Usage: python3 scripts/generate-test-audio.py <output dir>
Writes <slug>.mp3 per tune (via ffmpeg) and tunes.json describing them.
"""
import json
import math
import os
import random
import struct
import subprocess
import sys
import tempfile
import wave

RATE = 44100
FIRST_DOWNBEAT = 0.5
BARS = 48
TAIL = 1.0

# slug, artist, title, bpm, root frequency (Hz)
TUNES = [
    ("biglove", "Pete Heller", "Big Love", 128, 55.00),
    ("ontherun", "Big Time Charlie", "On The Run", 130, 61.74),
    ("tobeinlove", "Masters At Work", "To Be In Love", 126, 49.00),
    ("crazylove", "M J Cole", "Crazy Love", 134, 65.41),
    ("sweetlikechocolate", "Shanks And Bigfoot", "Sweet Like Chocolate", 136, 73.42),
    ("straightfromtheheart", "Doolally", "Straight From The Heart", 134, 58.27),
]


def kick_template():
    n = int(RATE * 0.30)
    out = []
    phase = 0.0
    for i in range(n):
        t = i / RATE
        freq = 45 + 110 * math.exp(-t * 28)
        phase += 2 * math.pi * freq / RATE
        out.append(math.sin(phase) * math.exp(-t * 9) * 0.9)
    return out


def hat_template(length=0.05, gain=0.18):
    n = int(RATE * length)
    rng = random.Random(7)
    return [(rng.random() * 2 - 1) * gain * math.exp(-(i / RATE) * 60) for i in range(n)]


def click_template():
    n = int(RATE * 0.04)
    return [math.sin(2 * math.pi * 1800 * i / RATE) * 0.5 * math.exp(-(i / RATE) * 90) for i in range(n)]


def add(buf, start, template):
    end = min(len(buf), start + len(template))
    for i in range(start, end):
        buf[i] += template[i - start]


def render(bpm, root):
    beat = 60.0 / bpm
    total = FIRST_DOWNBEAT + BARS * 4 * beat + TAIL
    n = int(total * RATE)
    buf = [0.0] * n
    kick, hat, click = kick_template(), hat_template(), click_template()

    for bar in range(BARS):
        for b in range(4):
            t = FIRST_DOWNBEAT + (bar * 4 + b) * beat
            add(buf, int(t * RATE), kick)
            add(buf, int((t + beat / 2) * RATE), hat)
            if b == 0:
                add(buf, int(t * RATE), click)

    # Bass: eighth-note pulse on the root, a fifth every fourth beat,
    # one chord change (up a minor third) for bars 9-16 of every 16.
    phase = 0.0
    for i in range(n):
        t = i / RATE
        if t < FIRST_DOWNBEAT or t >= total - TAIL:
            continue
        pos = (t - FIRST_DOWNBEAT) / beat
        bar = int(pos // 4)
        beat_in_bar = int(pos % 4)
        freq = root * (1.1892 if (bar % 16) >= 8 else 1.0)
        if beat_in_bar == 3:
            freq *= 1.5
        phase += 2 * math.pi * freq / RATE
        env = math.exp(-((pos * 2) % 1) * 3)
        s = math.sin(phase) + 0.3 * math.sin(2 * phase)
        buf[i] += s * env * 0.28

    peak = max(abs(x) for x in buf) or 1.0
    scale = 0.95 / peak
    return [int(max(-1.0, min(1.0, x * scale)) * 32767) for x in buf], total


def main(out_dir):
    os.makedirs(out_dir, exist_ok=True)
    manifest = []
    for slug, artist, title, bpm, root in TUNES:
        samples, duration = render(bpm, root)
        with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
            wav_path = tmp.name
        with wave.open(wav_path, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(RATE)
            w.writeframes(struct.pack("<%dh" % len(samples), *samples))
        mp3_path = os.path.join(out_dir, slug + ".mp3")
        subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", wav_path, "-codec:a", "libmp3lame", "-b:a", "160k", mp3_path], check=True)
        os.unlink(wav_path)
        manifest.append({"slug": slug, "artist": artist, "title": title, "bpm": bpm,
                         "firstDownbeatSeconds": FIRST_DOWNBEAT, "durationSeconds": round(duration, 3), "bars": BARS,
                         "file": slug + ".mp3"})
        print(f"{slug}.mp3  {bpm} BPM  {duration:.1f}s")
    with open(os.path.join(out_dir, "tunes.json"), "w") as f:
        json.dump(manifest, f, indent=2)


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "web/sites/default/files/selectamix/tunes")
