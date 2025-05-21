import banner from "vite-plugin-banner";
import type { PluginOption } from "vite";
import pkg from "../package.json";
import { distDir } from "./common";
/**
 * Create a banner for the assets.
 *
 * @param name Name that should appear in the banner.
 * @param suffix The subfolder in the ./assets/dist folder should the banner be added to.
 * @returns Banner content.
 */
export function createBanner(name: string) {
	return banner({
		outDir: distDir,
		// content: `/**\n * name: ${name}\n * version: v${pkg.version}\n * description: ${pkg.description}\n * author: ${pkg.author}\n * homepage: ${pkg.homepage}\n */`,
		content: `/**
 * name: ${name}
 * version: v${pkg.version} - Build: ${new Date().toISOString()}
 * description: ${pkg.description}
 * author: ${pkg.contributors ? pkg.contributors.map((c) => c.name).join(", ") : "unknown"}
 * author_url: ${pkg.contributors ? pkg.contributors.map((c) => c.url).join(", ") : "unknown"}
 * homepage: ${pkg.homepage}
 */`,
		verify: true,
	}) as PluginOption;
}
