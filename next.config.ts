import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  serverExternalPackages: ["mysql2", "sharp"],
  experimental: {
    serverActions: {
      // The dev server runs behind the Codespaces proxy, which forwards requests
      // with `x-forwarded-host` set to the public tunnel host while the browser
      // sends `origin: http://localhost:3000`. Next.js treats that mismatch as a
      // CSRF attempt and aborts every Server Action ("Invalid Server Actions request").
      // Allow the local/proxied origins so login forms keep working.
      allowedOrigins: [
        "localhost:3000",
        "127.0.0.1:3000",
        "localhost",
        "127.0.0.1",
        "*.app.github.dev",
      ],
    },
  },
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
