import { v4wp } from "@kucrut/vite-for-wp";
import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { wp_scripts } from "@kucrut/vite-for-wp/plugins";
import path from "node:path";
import license from "rollup-plugin-license";
import { join } from "node:path";
import { createBanner } from "./bin/assets-banner";

export default defineConfig({
	server: {
		port: 3000,
	},
	/**
	 * @see https://github.com/ciscoheat/sveltekit-superforms/issues/321
	 */
	optimizeDeps: {
		exclude: [
			"$app/environment",
			"$app/forms",
			"$app/stores",
			"$app/navigation",
		],
	},
	plugins: [
		wp_scripts(),
		svelte(),
		v4wp({
			//
			input: [
				"assets/ts/admin-settings/index.ts",
				"assets/ts/tools/product-description/index.ts",
			],
		}),
		license({
			thirdParty: {
				includePrivate: false,
				includeSelf: false,
				output: {
					file: join(__dirname, "dist", "dependencies.txt"),
				},
			},
		}),
		createBanner("Productbird"),
	],
	css: {
		preprocessorOptions: {
			scss: {
				api: "modern",
			},
		},
	},
	resolve: {
		alias: [
			{ find: "$lib", replacement: path.resolve("./assets/ts/lib") },
			{
				find: "$components",
				replacement: path.resolve("./assets/ts/lib/components"),
			},
			{
				find: "$app",
				replacement: path.resolve("./assets/ts/lib/sk/app"),
			},
			{
				find: "$admin-settings",
				replacement: path.resolve("./assets/ts/admin-settings"),
			},
			{
				find: "$tools",
				replacement: path.resolve("./assets/ts/tools"),
			},
		],
	},

	build: {
		sourcemap: true,
		rollupOptions: {
			output: {
				assetFileNames: (assetInfo) => {
					const extType = assetInfo.name.split(".").at(1);
					if (/png|jpe?g|svg|gif|tiff|bmp|ico/i.test(extType)) {
						return "images/[name]-[hash][extname]";
					}
					if (/css/i.test(extType)) {
						return "css/[name]-[hash][extname]";
					}
					return "[name]-[hash][extname]";
				},
				chunkFileNames: "js/[name]-[hash].js",
				entryFileNames: "js/[name]-[hash].js",
			},
		},
	},
});
