(function () {
  const { registerPlugin } = wp.plugins;
  const { PluginDocumentSettingPanel } = wp.editPost;
  const { createElement, useState } = wp.element;
  const { Button, Notice } = wp.components;
  const { select, dispatch } = wp.data;

  async function postAjax(action, data) {
    const form = new URLSearchParams();
    form.append("action", action);
    Object.keys(data || {}).forEach((k) => form.append(k, data[k]));

    const res = await fetch(AIG_EDITOR.ajax, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: form.toString(),
    });

    const json = await res.json().catch(() => null);
    if (!json) throw new Error("Invalid server response");
    if (!json.success) throw new Error(json.data || "Request failed");
    return json.data;
  }

  // ✅ Always read the LATEST edited title at click time
  function getLiveEditedTitle() {
    try {
      const t = select("core/editor").getEditedPostAttribute("title");
      return (t || "").trim();
    } catch (e) {
      return "";
    }
  }

  function AIGPanel() {
    const [loading, setLoading] = useState(false);
    const [msg, setMsg] = useState(null);
    const [preview, setPreview] = useState("");

    const postId = select("core/editor").getCurrentPostId();

    const onGenerate = async () => {
      setMsg(null);
      setPreview("");

      // ✅ Get latest title NOW (not from render time)
      const titleNow = getLiveEditedTitle();

      if (!titleNow) {
        setMsg({ type: "error", text: "Post title is empty. Please add a title first." });
        return;
      }

      setLoading(true);

      try {
        const data = await postAjax("aig_generate_set_featured", {
          nonce: AIG_EDITOR.nonce,
          post_id: String(postId),
          title: titleNow, // ✅ always latest
        });

        // ✅ Immediately update Featured Image in Gutenberg UI
        dispatch("core/editor").editPost({ featured_media: data.attachment_id });

        setPreview(data.url || "");
        setMsg({ type: "success", text: `Featured Image set for: "${titleNow}"` });
      } catch (e) {
        setMsg({ type: "error", text: e.message || "Failed to generate featured image" });
      } finally {
        setLoading(false);
      }
    };

    return createElement(
      PluginDocumentSettingPanel,
      {
        name: "aig-image-generate",
        title: "Image Generate",
        className: "aig-editor-panel",
      },
      msg &&
        createElement(
          Notice,
          { status: msg.type === "success" ? "success" : "error", isDismissible: true },
          msg.text
        ),

      createElement(
        Button,
        {
          isPrimary: true,
          isBusy: loading,
          disabled: loading,
          onClick: onGenerate,
          style: { width: "100%" },
        },
        loading ? "Generating..." : "Generate Featured Image"
      ),

      preview &&
        createElement(
          "div",
          { style: { marginTop: "10px" } },
          createElement("img", {
            src: preview,
            style: { width: "100%", borderRadius: "10px", border: "1px solid rgba(0,0,0,0.12)" },
            alt: "",
          })
        )
    );
  }

  registerPlugin("aig-image-generate-plugin", { render: AIGPanel });
})();
