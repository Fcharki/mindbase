import html
import os
import re

from bs4 import BeautifulSoup


# Only treat the payload as markup when it actually contains a tag, otherwise
# prose such as "if x < 5 and y > 2" gets parsed as one and silently loses text.
_TAG_LIKE = re.compile(r"</?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*?)?/?>")

_HTML_COMMENT = re.compile(r"<!--.*?-->", re.DOTALL)

# Removed outright: their text is never prose worth summarizing.
_DISCARDED_TAGS = (
    "script",
    "style",
    "noscript",
    "template",
    "svg",
    "math",
    "canvas",
    "iframe",
    "object",
    "embed",
)

_CODE_TAGS = ("pre", "code", "kbd", "samp", "tt")

# These introduce a word boundary. Inline tags deliberately are not listed, so
# "<strong>bold</strong>text" keeps rendering as "boldtext" instead of being
# torn into two words.
_BLOCK_TAGS = (
    "address", "article", "aside", "blockquote", "br", "dd", "div", "dl", "dt",
    "fieldset", "figcaption", "figure", "footer", "form", "h1", "h2", "h3",
    "h4", "h5", "h6", "header", "hr", "li", "main", "nav", "ol", "p", "pre",
    "section", "table", "tbody", "td", "tfoot", "th", "thead", "tr", "ul",
)

_FENCED_CODE = re.compile(r"```.*?```|```.*\Z", re.DOTALL)
_FENCE_LINE = re.compile(r"^[ \t]*```[^\n]*$", re.MULTILINE)
_INLINE_CODE = re.compile(r"`+[^`]*`+")
_IMAGE = re.compile(r"!\[[^\]]*\]\([^)]*\)")
_LINK = re.compile(r"\[([^\]]+)\]\([^)]*\)")
_ATX_HEADING = re.compile(r"^[ \t]{0,3}#{1,6}[ \t]*", re.MULTILINE)
_SETEXT_RULE = re.compile(r"^[ \t]*(?:=+|-+)[ \t]*$", re.MULTILINE)
_BLOCKQUOTE = re.compile(r"^[ \t]*>+[ \t]?", re.MULTILINE)
_LIST_MARKER = re.compile(r"^[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+", re.MULTILINE)
_HORIZONTAL_RULE = re.compile(r"^[ \t]*(?:[-*_][ \t]*){3,}$", re.MULTILINE)

# Emphasis patterns refuse to open or close inside a word so that identifiers
# such as "some_var_name" and "*args" survive untouched. The underscore forms
# are deliberately absent: "_italic_" and "__bold__" are indistinguishable
# from snake_case names and dunders such as "__init__", and a note that is
# mostly identifiers would come back quietly mangled.
_STRIKE = re.compile(r"~~(?=\S)(.+?)(?<=\S)~~", re.DOTALL)
_BOLD_ASTERISK = re.compile(r"(?<!\*)\*\*(?=\S)(.+?)(?<=\S)\*\*(?!\*)", re.DOTALL)
_ITALIC_ASTERISK = re.compile(r"(?<![\*\w])\*(?=\S)(.+?)(?<=\S)\*(?!\*)", re.DOTALL)


def _env_flag(name: str, default: bool) -> bool:
    value = os.getenv(name)

    if value is None:
        return default

    return value.strip().lower() in {"1", "true", "yes", "on"}


def _strip_markup(text: str, drop_code: bool) -> str:
    """Flatten HTML to text, separating blocks but not inline spans."""
    soup = BeautifulSoup(text, "html.parser")

    for tag in soup.find_all(_DISCARDED_TAGS):
        tag.extract()

    if drop_code:
        for tag in soup.find_all(_CODE_TAGS):
            tag.extract()

    for tag in soup.find_all(_BLOCK_TAGS):
        tag.insert_before("\n")
        tag.insert_after("\n")

    # No separator: inline tags must not gain a space they never had.
    return soup.get_text()


def _strip_markdown(text: str, drop_code: bool) -> str:
    """Remove markdown syntax, keeping the words it wraps."""
    if drop_code:
        text = _FENCED_CODE.sub(" ", text)
        text = _INLINE_CODE.sub(" ", text)
    else:
        text = _FENCE_LINE.sub(" ", text)
        text = text.replace("```", " ").replace("`", "")

    text = _IMAGE.sub(" ", text)
    text = _LINK.sub(r"\1", text)

    text = _HORIZONTAL_RULE.sub(" ", text)
    text = _SETEXT_RULE.sub(" ", text)
    text = _ATX_HEADING.sub("", text)
    text = _BLOCKQUOTE.sub("", text)
    text = _LIST_MARKER.sub("", text)

    text = _STRIKE.sub(r"\1", text)
    text = _BOLD_ASTERISK.sub(r"\1", text)
    text = _ITALIC_ASTERISK.sub(r"\1", text)

    return text


def clean_text(raw: str, strip_formatting: bool | None = None, drop_code: bool | None = None) -> str:
    """Flatten rich text into the plain prose a summarization model expects.

    Headings, emphasis, blockquotes, lists, links and code syntax are all
    noise to a seq2seq summarizer, and block markup left in place glues
    adjacent paragraphs together. Passing None for either option falls back to
    the AI_STRIP_FORMATTING and AI_DROP_CODE_BLOCKS environment defaults.
    """
    if strip_formatting is None:
        strip_formatting = _env_flag("AI_STRIP_FORMATTING", True)

    if drop_code is None:
        drop_code = _env_flag("AI_DROP_CODE_BLOCKS", True)

    text = raw or ""

    if not strip_formatting:
        return text.strip()

    text = _HTML_COMMENT.sub(" ", text)
    was_markup = _TAG_LIKE.search(text) is not None

    if was_markup:
        text = _strip_markup(text, drop_code)

    text = _strip_markdown(text, drop_code)

    if not was_markup:
        # bs4 already resolved entities while parsing markup.
        text = html.unescape(text)

    return re.sub(r"\s+", " ", text).strip()
