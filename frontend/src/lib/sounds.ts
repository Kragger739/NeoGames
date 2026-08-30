/**
 * Short synthesized tones (Web Audio oscillator + gain envelope) — no
 * audio files. This is a deliberate substitute: the redesign brief asked
 * for sound effects, but no licensed sound source is available to pull
 * clips from in this environment. A muted-by-default-off toggle persists
 * to localStorage so a whole table doesn't get an unwanted noise burst.
 */

export type SoundName = "win" | "fail" | "correct" | "tick" | "join";

const MUTE_KEY = "neogames_sound_muted";

let ctx: AudioContext | null = null;

function getContext(): AudioContext | null {
  if (typeof window === "undefined") return null;
  const AudioCtx = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (!AudioCtx) return null;
  if (!ctx) ctx = new AudioCtx();
  return ctx;
}

export function isSoundMuted(): boolean {
  try {
    return localStorage.getItem(MUTE_KEY) === "1";
  } catch {
    return false;
  }
}

export function setSoundMuted(muted: boolean): void {
  try {
    localStorage.setItem(MUTE_KEY, muted ? "1" : "0");
  } catch {
    // Storage can be unavailable (private mode) - muting just won't persist.
  }
}

interface Tone {
  freq: number;
  type: OscillatorType;
  duration: number;
  gain: number;
  delay?: number;
}

// Each sound is a tiny chord/arpeggio, not a single beep - what actually
// reads as "win" vs "fail" vs "tick" to a human ear.
const SEQUENCES: Record<SoundName, Tone[]> = {
  win: [
    { freq: 523.25, type: "triangle", duration: 0.12, gain: 0.09 },
    { freq: 659.25, type: "triangle", duration: 0.12, gain: 0.09, delay: 0.09 },
    { freq: 783.99, type: "triangle", duration: 0.22, gain: 0.1, delay: 0.18 },
  ],
  fail: [
    { freq: 220, type: "sawtooth", duration: 0.16, gain: 0.07 },
    { freq: 174.61, type: "sawtooth", duration: 0.24, gain: 0.07, delay: 0.12 },
  ],
  correct: [{ freq: 880, type: "sine", duration: 0.1, gain: 0.08 }],
  tick: [{ freq: 1046.5, type: "square", duration: 0.05, gain: 0.04 }],
  join: [
    { freq: 440, type: "sine", duration: 0.08, gain: 0.07 },
    { freq: 659.25, type: "sine", duration: 0.14, gain: 0.07, delay: 0.07 },
  ],
};

export function playSound(name: SoundName): void {
  if (isSoundMuted()) return;

  const audioCtx = getContext();
  if (!audioCtx) return;

  if (audioCtx.state === "suspended") void audioCtx.resume();

  for (const tone of SEQUENCES[name]) {
    const startAt = audioCtx.currentTime + (tone.delay ?? 0);
    const osc = audioCtx.createOscillator();
    const gainNode = audioCtx.createGain();

    osc.type = tone.type;
    osc.frequency.setValueAtTime(tone.freq, startAt);

    gainNode.gain.setValueAtTime(0, startAt);
    gainNode.gain.linearRampToValueAtTime(tone.gain, startAt + 0.015);
    gainNode.gain.exponentialRampToValueAtTime(0.0001, startAt + tone.duration);

    osc.connect(gainNode);
    gainNode.connect(audioCtx.destination);
    osc.start(startAt);
    osc.stop(startAt + tone.duration + 0.02);
  }
}
