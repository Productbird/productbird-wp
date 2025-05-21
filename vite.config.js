import { v4wp } from "@kucrut/vite-for-wp";
import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";
import { wp_scripts } from "@kucrut/vite-for-wp/plugins";
import path from "node:path";
import license from "rollup-plugin-license";
import { join } from "node:path";

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
				"assets/admin-settings/index.ts",
				"assets/product-description/index.ts",
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
			{ find: "$lib", replacement: path.resolve("./assets/lib") },
			{
				find: "$components",
				replacement: path.resolve("./assets/lib/components"),
			},
			{
				find: "$app",
				replacement: path.resolve("./assets/lib/sk/app"),
			},
			{
				find: "$admin-settings",
				replacement: path.resolve("./assets/admin-settings"),
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
