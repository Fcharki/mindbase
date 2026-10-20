"use client";

import { useEffect, type ReactNode } from "react";
import { useEditor, EditorContent, useEditorState } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";

interface TiptapProps {
  content?: string;
  onChange?: (content: string) => void;
}

interface ToolbarButtonProps {
  label: string;
  title?: string;
  active?: boolean;
  disabled?: boolean;
  onClick: () => void;
  children: ReactNode;
}

function ToolbarButton({
  label,
  title,
  active = false,
  disabled = false,
  onClick,
  children,
}: ToolbarButtonProps) {
  return (
    <button
      type="button"
      aria-label={label}
      aria-pressed={active}
      title={title ?? label}
      onClick={onClick}
      disabled={disabled}
      className={
        "flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition disabled:cursor-not-allowed disabled:opacity-40 " +
        (active
          ? "bg-[#EEEDFF] dark:bg-[#262449] text-[#5146E5]"
          : "text-[#686873] dark:text-[#b0b0b9] hover:bg-[#F2F1F7] dark:hover:bg-[#2c2c33] hover:text-[#20202A]")
      }
    >
      {children}
    </button>
  );
}

function ToolbarSeparator() {
  return <span aria-hidden="true" className="mx-1 h-5 w-px shrink-0 bg-[#E4E3E9] dark:bg-[#34343c]" />;
}

type IconProps = { className?: string };

const iconProps = {
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.7,
  strokeLinecap: "round",
  strokeLinejoin: "round",
} as const;

function BoldIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z" />
      <path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z" />
    </svg>
  );
}

function ItalicIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <line x1="19" y1="4" x2="10" y2="4" />
      <line x1="14" y1="20" x2="5" y2="20" />
      <line x1="15" y1="4" x2="9" y2="20" />
    </svg>
  );
}

function UnderlineIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M6 3v7a6 6 0 0 0 6 6 6 6 0 0 0 6-6V3" />
      <line x1="4" y1="21" x2="20" y2="21" />
    </svg>
  );
}

function StrikeIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M16 4H9a3 3 0 0 0-2.83 4" />
      <path d="M14 12a4 4 0 0 1 0 8H6" />
      <line x1="4" y1="12" x2="20" y2="12" />
    </svg>
  );
}

function CodeIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <polyline points="16 18 22 12 16 6" />
      <polyline points="8 6 2 12 8 18" />
    </svg>
  );
}

function CodeBlockIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <polyline points="4 17 10 11 4 5" />
      <line x1="12" y1="19" x2="20" y2="19" />
    </svg>
  );
}

function BulletListIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <line x1="8" y1="6" x2="21" y2="6" />
      <line x1="8" y1="12" x2="21" y2="12" />
      <line x1="8" y1="18" x2="21" y2="18" />
      <line x1="3" y1="6" x2="3.01" y2="6" />
      <line x1="3" y1="12" x2="3.01" y2="12" />
      <line x1="3" y1="18" x2="3.01" y2="18" />
    </svg>
  );
}

function OrderedListIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <line x1="10" y1="6" x2="21" y2="6" />
      <line x1="10" y1="12" x2="21" y2="12" />
      <line x1="10" y1="18" x2="21" y2="18" />
      <path d="M4 6h1v4" />
      <path d="M4 10h2" />
      <path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />
    </svg>
  );
}

function BlockquoteIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z" />
      <path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z" />
    </svg>
  );
}

function LinkIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
      <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
    </svg>
  );
}

function MinusIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  );
}

function UndoIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <polyline points="1 4 1 10 7 10" />
      <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
    </svg>
  );
}

function RedoIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <polyline points="23 4 23 10 17 10" />
      <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
    </svg>
  );
}

function RemoveFormatIcon({ className }: IconProps) {
  return (
    <svg {...iconProps} className={className ?? "h-4 w-4"} aria-hidden="true">
      <path d="M4 7V4h16v3" />
      <path d="M5 20h6" />
      <path d="M13 4 8 20" />
    </svg>
  );
}

const DEFAULT_TOOLBAR_STATE = {
  bold: false,
  italic: false,
  underline: false,
  strike: false,
  code: false,
  codeBlock: false,
  bulletList: false,
  orderedList: false,
  blockquote: false,
  headingLevel: null as number | null,
  linkHref: null as string | null,
  canUndo: false,
  canRedo: false,
};

const Tiptap = ({ content = "", onChange }: TiptapProps) => {
  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
      }),
    ],
    content,
    immediatelyRender: false,
    onUpdate: ({ editor: current }) => {
      onChange?.(current.getHTML());
    },
  });

  const state = useEditorState({
    editor,
    selector: ({ editor: current }) => {
      if (!current) {
        return DEFAULT_TOOLBAR_STATE;
      }

      return {
        bold: current.isActive("bold"),
        italic: current.isActive("italic"),
        underline: current.isActive("underline"),
        strike: current.isActive("strike"),
        code: current.isActive("code"),
        codeBlock: current.isActive("codeBlock"),
        bulletList: current.isActive("bulletList"),
        orderedList: current.isActive("orderedList"),
        blockquote: current.isActive("blockquote"),
        headingLevel: current.isActive("heading")
          ? (current.getAttributes("heading").level as number)
          : null,
        linkHref: (current.getAttributes("link").href as string | undefined) ?? null,
        canUndo: current.can().undo(),
        canRedo: current.can().redo(),
      };
    },
  });

  const toolbar = state ?? DEFAULT_TOOLBAR_STATE;

  useEffect(() => {
    if (!editor || editor.isDestroyed) {
      return;
    }

    if (editor.getHTML() !== content) {
      editor.commands.setContent(content, { emitUpdate: false });
    }
  }, [content, editor]);

  function handleLink() {
    if (!editor) {
      return;
    }

    if (toolbar.linkHref) {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }

    const url = window.prompt("Link URL", "https://");

    if (url === null) {
      return;
    }

    if (url === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }

    editor.chain().focus().extendMarkRange("link").setLink({ href: url }).run();
  }

  return (
    <div className="overflow-hidden rounded-lg">
      <div className="flex flex-wrap items-center gap-1 border-b border-[#D7D5DE] dark:border-[#34343c] bg-[#FBFAF8] dark:bg-[#1b1b1f] px-2 py-1.5">
        <select
          aria-label="Block type"
          value={toolbar.headingLevel === null ? "paragraph" : String(toolbar.headingLevel)}
          onChange={(event) => {
            if (!editor) {
              return;
            }

            const value = event.target.value;

            if (value === "paragraph") {
              editor.chain().focus().setParagraph().run();
            } else {
              editor
                .chain()
                .focus()
                .toggleHeading({ level: Number(value) as 1 | 2 | 3 })
                .run();
            }
          }}
          className="h-8 rounded-md border border-[#D7D5DE] dark:border-[#34343c] bg-white dark:bg-[#1b1b1f] px-2 text-sm text-[#20202A] dark:text-[#ececf1] outline-none transition focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a]"
        >
          <option value="paragraph">Paragraph</option>
          <option value="1">Heading 1</option>
          <option value="2">Heading 2</option>
          <option value="3">Heading 3</option>
        </select>

        <ToolbarSeparator />

        <ToolbarButton label="Bold" title="Bold (Ctrl+B)" active={toolbar.bold} onClick={() => editor?.chain().focus().toggleBold().run()}>
          <BoldIcon />
        </ToolbarButton>

        <ToolbarButton label="Italic" title="Italic (Ctrl+I)" active={toolbar.italic} onClick={() => editor?.chain().focus().toggleItalic().run()}>
          <ItalicIcon />
        </ToolbarButton>

        <ToolbarButton label="Underline" title="Underline (Ctrl+U)" active={toolbar.underline} onClick={() => editor?.chain().focus().toggleUnderline().run()}>
          <UnderlineIcon />
        </ToolbarButton>

        <ToolbarButton label="Strikethrough" title="Strikethrough" active={toolbar.strike} onClick={() => editor?.chain().focus().toggleStrike().run()}>
          <StrikeIcon />
        </ToolbarButton>

        <ToolbarButton label="Inline code" title="Inline code" active={toolbar.code} onClick={() => editor?.chain().focus().toggleCode().run()}>
          <CodeIcon />
        </ToolbarButton>

        <ToolbarSeparator />

        <ToolbarButton label="Bullet list" active={toolbar.bulletList} onClick={() => editor?.chain().focus().toggleBulletList().run()}>
          <BulletListIcon />
        </ToolbarButton>

        <ToolbarButton label="Ordered list" active={toolbar.orderedList} onClick={() => editor?.chain().focus().toggleOrderedList().run()}>
          <OrderedListIcon />
        </ToolbarButton>

        <ToolbarButton label="Blockquote" active={toolbar.blockquote} onClick={() => editor?.chain().focus().toggleBlockquote().run()}>
          <BlockquoteIcon />
        </ToolbarButton>

        <ToolbarButton label="Code block" active={toolbar.codeBlock} onClick={() => editor?.chain().focus().toggleCodeBlock().run()}>
          <CodeBlockIcon />
        </ToolbarButton>

        <ToolbarButton
          label={toolbar.linkHref ? "Remove link" : "Add link"}
          title="Link"
          active={Boolean(toolbar.linkHref)}
          onClick={handleLink}
        >
          <LinkIcon />
        </ToolbarButton>

        <ToolbarButton label="Horizontal rule" title="Horizontal rule" onClick={() => editor?.chain().focus().setHorizontalRule().run()}>
          <MinusIcon />
        </ToolbarButton>

        <ToolbarSeparator />

        <ToolbarButton label="Clear formatting" title="Clear formatting" onClick={() => editor?.chain().focus().clearNodes().unsetAllMarks().run()}>
          <RemoveFormatIcon />
        </ToolbarButton>

        <ToolbarSeparator />

        <ToolbarButton label="Undo" title="Undo (Ctrl+Z)" disabled={!toolbar.canUndo} onClick={() => editor?.chain().focus().undo().run()}>
          <UndoIcon />
        </ToolbarButton>

        <ToolbarButton label="Redo" title="Redo (Ctrl+Shift+Z)" disabled={!toolbar.canRedo} onClick={() => editor?.chain().focus().redo().run()}>
          <RedoIcon />
        </ToolbarButton>
      </div>

      <EditorContent editor={editor} />
    </div>
  );
};

export default Tiptap;