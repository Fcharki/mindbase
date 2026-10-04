"""Flattening rich text for the summarizer.

The summarizer is a seq2seq model that was trained on prose. Markup it has
never seen is not merely unhelpful, it is destructive: block tags run together
and weld the last word of one paragraph to the first word of the next, and
emphasis markers read as content. These tests pin the behaviour of the text
that actually reaches the model.
"""

import pytest

from app.util import clean_text, is_degenerate_output


class TestHtmlMarkup:
    def test_headings_lose_their_markup_but_keep_their_words(self):
        html = "<h2>Release Plan</h2><p>We shipped the parser last week.</p>"

        assert clean_text(html) == "Release Plan We shipped the parser last week."

    def test_adjacent_paragraphs_keep_the_boundary_between_them(self):
        html = "<p>We hiked</p><p>the Blue Ridge trail.</p>"

        assert clean_text(html) == "We hiked the Blue Ridge trail."

    def test_list_items_stay_separate_rather_than_welding_together(self):
        html = "<ul><li>first</li><li>second</li></ul>"

        assert clean_text(html) == "first second"

    def test_inline_tags_do_not_invent_a_space(self):
        html = "<p>read the <strong>README</strong>first</p>"

        assert clean_text(html) == "read the READMEfirst"

    def test_blockquote_content_is_kept_without_the_marker(self):
        html = "<blockquote><p>The API is stable.</p></blockquote>"

        assert clean_text(html) == "The API is stable."

    def test_a_line_break_separates_words(self):
        assert clean_text("<p>alpha<br>beta</p>") == "alpha beta"

    def test_script_and_style_contents_are_discarded(self):
        html = "<style>p{color:red}</style><script>alert(1)</script><p>Kept.</p>"

        assert clean_text(html) == "Kept."

    def test_entities_are_decoded(self):
        html = "<p>Tom &amp; Jerry said &quot;hello&quot;</p>"

        assert clean_text(html) == 'Tom & Jerry said "hello"'

    def test_prose_containing_angle_brackets_is_not_parsed_as_markup(self):
        assert clean_text("if x < 5 and y > 2") == "if x < 5 and y > 2"


class TestCodeBlocks:
    def test_code_is_dropped_by_default(self):
        html = "<p>Fix the bug:</p><pre><code>rm -rf /</code></pre><p>Then restart.</p>"

        assert clean_text(html) == "Fix the bug: Then restart."

    def test_inline_code_is_dropped_by_default(self):
        assert clean_text("<p>Run <code>npm test</code> now.</p>") == "Run now."

    def test_code_is_kept_unmarked_when_asked(self):
        html = "<p>Run <code>npm test</code> now.</p>"

        assert clean_text(html, drop_code=False) == "Run npm test now."

    def test_fenced_markdown_is_dropped_by_default(self):
        text = "Fix the bug:\n```bash\nrm -rf /\n```\nThen restart."

        assert clean_text(text) == "Fix the bug: Then restart."

    def test_fenced_markdown_keeps_its_body_when_asked(self):
        text = "Fix the bug:\n```bash\nrm -rf /\n```\nThen restart."

        assert clean_text(text, drop_code=False) == "Fix the bug: rm -rf / Then restart."

    def test_an_unterminated_fence_still_gets_dropped(self):
        text = "Intro paragraph.\n```python\nprint(1)\n"

        assert clean_text(text) == "Intro paragraph."

    def test_a_fence_collapsed_onto_one_line_keeps_its_body_when_asked(self):
        # ChunkingService flattens entry content to a single line before storing
        # it, so a pasted code block arrives here with its newlines gone. Treated
        # as a fence line it used to delete the whole thing, which emptied the
        # chunk and failed indexing of the entire entry.
        text = "```sql SELECT id FROM orders WHERE total IS NULL;```"

        assert clean_text(text, drop_code=False) == (
            "sql SELECT id FROM orders WHERE total IS NULL;"
        )

    def test_a_lone_fence_line_is_still_stripped_when_asked(self):
        text = "Fix it:\n```bash\nrm -rf /\n```\nThen restart."

        assert clean_text(text, drop_code=False) == "Fix it: rm -rf / Then restart."


class TestMarkdownSyntax:
    def test_emphasis_markers_are_removed(self):
        assert clean_text("This is **bold** and *italic*.") == "This is bold and italic."

    def test_strikethrough_keeps_its_text(self):
        assert clean_text("Keep ~~this~~ part.") == "Keep this part."

    def test_underscore_emphasis_is_left_alone_to_protect_identifiers(self):
        text = "Rename the column to __is_active__ and set some_flag_name."

        assert clean_text(text) == text

    def test_links_keep_their_text_and_lose_their_url(self):
        assert clean_text("See [the docs](https://example.com/a?b=1) for more.") == (
            "See the docs for more."
        )

    def test_images_are_removed_entirely(self):
        assert clean_text("Before ![alt text](chart.png) after") == "Before after"

    def test_underscored_identifiers_survive_emphasis_stripping(self):
        text = "Set some_var_name in the __init__ method before running it."

        assert clean_text(text) == text

    def test_asterisks_around_a_word_are_not_silently_removed(self):
        assert clean_text("rate * 3 = 9") == "rate * 3 = 9"

    def test_a_bare_underscore_inside_a_word_survives(self):
        assert clean_text("call some_method_name()") == "call some_method_name()"


class TestConfiguration:
    def test_stripping_can_be_turned_off_entirely(self):
        html = "<h2>Title</h2><p>Body **bold**.</p>"

        assert clean_text(html, strip_formatting=False) == "<h2>Title</h2><p>Body **bold**.</p>"

    def test_strip_formatting_is_read_from_the_environment(self, monkeypatch):
        monkeypatch.setenv("AI_STRIP_FORMATTING", "false")

        assert clean_text("# Title") == "# Title"

    def test_drop_code_is_read_from_the_environment(self, monkeypatch):
        monkeypatch.setenv("AI_DROP_CODE_BLOCKS", "false")

        assert clean_text("Run `npm test` now.") == "Run npm test now."

    def test_explicit_arguments_beat_the_environment(self, monkeypatch):
        monkeypatch.setenv("AI_STRIP_FORMATTING", "false")

        assert clean_text("# Title", strip_formatting=True) == "Title"


class TestEdgeCases:
    @pytest.mark.parametrize("empty", ["", None, "   ", "\n\n"])
    def test_blank_input_yields_blank_output(self, empty):
        assert clean_text(empty) == ""

    def test_input_without_markup_is_only_normalised(self):
        assert clean_text("  Plain\n\nprose   here.  ") == "Plain prose here."

    def test_markup_that_hides_its_only_text_behind_a_dropped_tag(self):
        assert clean_text("<pre><code>secret()</code></pre>") == ""


class TestDegeneracyDetection:
    """A loop is fluent, plausible and wrong, so it must never be served."""

    def test_blank_output_is_degenerate(self):
        assert is_degenerate_output("") is True
        assert is_degenerate_output("   ") is True

    def test_an_ordinary_summary_is_not_degenerate(self):
        assert (
            is_degenerate_output(
                "The gateway exhausted its connection pool after the "
                "idempotency cache grew without bound."
            )
            is False
        )

    def test_a_repeated_phrase_is_degenerate(self):
        assert is_degenerate_output("timeout 504 " * 12) is True

    def test_a_low_unique_word_ratio_is_degenerate(self):
        assert is_degenerate_output("the the the the the the the the") is True

    def test_short_text_is_judged_on_repetition_not_ratio(self):
        # Summaries are legitimately repetitive, and three words is too short
        # to judge a vocabulary ratio on.
        assert is_degenerate_output("timeout 504") is False
        assert is_degenerate_output("The parser parses. The lexer lexes.") is False

    def test_a_long_summary_repeating_key_terms_is_allowed(self):
        text = (
            "The parser was rewritten because the parser could not handle "
            "the lexer input; the parser now shares code with the lexer."
        )

        assert is_degenerate_output(text) is False
