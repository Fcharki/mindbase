import type { Metadata } from "next";
import { Figtree } from "next/font/google";
import "./globals.css";
import { AuthProvider } from "./Providers/AuthProvider";
import { ThemeProvider } from "./Providers/ThemeProvider";
import { ToastProvider } from "./Providers/ToastProvider";

const figtree = Figtree({
  variable: "--font-figtree",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Mindbase",
  description: "Shape your thinking",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" suppressHydrationWarning className={`${figtree.variable} h-full antialiased`}>
      <body className="min-h-full flex flex-col bg-white text-[#20202A] dark:bg-[#0a0a0a] dark:text-[#ededed]">
        <ThemeProvider>
          <AuthProvider>
            {children}
            <ToastProvider />
          </AuthProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}