import { z } from "zod";

export enum Tone {
	Expert = "expert",
	Daring = "daring",
	Playful = "playful",
	Sophisticated = "sophisticated",
	Persuasive = "persuasive",
	Supportive = "supportive",
}

export enum Formality {
	Formal = "formal",
	Informal = "informal",
}

// For backward compatibility and zod validation
export const TONE_OPTIONS = Object.values(Tone);
export const FORMALITY_LEVELS = Object.values(Formality);

export const tone = z.nativeEnum(Tone);
export const formality = z.nativeEnum(Formality);

export type ToneType = z.infer<typeof tone>;
export type FormalityType = z.infer<typeof formality>;
