import path, { resolve } from "node:path";

export const pluginDir = resolve(__dirname, "..");
export const distDir = path.join(pluginDir, "dist");
