import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import { VitePWA } from "vite-plugin-pwa";

export default defineConfig({
    // [PERBAIKAN UTAMA] Tambahkan blok 'server' untuk mengatasi masalah CORS
    server: {
        host: "localhost", // Selalu gunakan localhost, bukan [::1]
        hmr: {
            host: "localhost",
        },
    },
    plugins: [
        laravel({
            input: [
                "resources/css/app.css",
                "resources/css/annotation.css",
                "resources/css/evaluate.css",
                "resources/css/responsive.css", // <-- TAMBAHKAN BARIS INI
                "resources/js/app.js",
                "resources/js/annotation.js",
                "resources/js/evaluate.js",
            ],
            refresh: true,
        }),
        VitePWA({
            registerType: "autoUpdate",
            workbox: {
                globPatterns: ["**/*.{js,css,html,ico,png,svg,woff2,ttf,eot}"],
            },
            manifest: {
                name: "Sistem Prediksi Kematangan Melon",
                short_name: "MelonPrediksi",
                description:
                    "Aplikasi untuk memprediksi kematangan buah melon menggunakan Machine Learning.",
                theme_color: "#3b71ca",
                background_color: "#ffffff",
                display: "standalone",
                scope: "/",
                start_url: "/",
                icons: [
                    {
                        src: "/images/icons/icon-72x72.png",
                        sizes: "72x72",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-96x96.png",
                        sizes: "96x96",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-128x128.png",
                        sizes: "128x128",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-144x144.png",
                        sizes: "144x144",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-152x152.png",
                        sizes: "152x152",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-192x192.png",
                        sizes: "192x192",
                        type: "image/png",
                        purpose: "any maskable",
                    },
                    {
                        src: "/images/icons/icon-384x384.png",
                        sizes: "384x384",
                        type: "image/png",
                    },
                    {
                        src: "/images/icons/icon-512x512.png",
                        sizes: "512x512",
                        type: "image/png",
                    },
                ],
            },
        }),
    ],
});
