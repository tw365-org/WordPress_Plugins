(function () {
  function qs(root, sel) { return root.querySelector(sel); }

  async function postAjax(action, data) {
    const form = new URLSearchParams();
    form.append("action", action);
    Object.keys(data || {}).forEach((k) => form.append(k, data[k]));

    const res = await fetch(AIG.ajax, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: form.toString(),
    });

    const json = await res.json().catch(() => null);
    if (!json) throw new Error("Invalid server response");
    if (!json.success) throw new Error(json.data || "Request failed");
    return json.data;
  }

  function showError(root, msg) {
    const box = qs(root, ".aig-alert-error");
    box.textContent = msg || "Something went wrong";
    box.style.display = "block";
  }

  function clearError(root) {
    const box = qs(root, ".aig-alert-error");
    box.style.display = "none";
    box.textContent = "";
  }

  function init(root) {
    const input = qs(root, ".aig-input");
    const btn = qs(root, ".aig-btn");
    const result = qs(root, ".aig-result");
    const img = qs(root, ".aig-preview");
    const download = qs(root, ".aig-download");
    const saveBtn = qs(root, ".aig-save");
    const savedBox = qs(root, ".aig-saved");

    let lastUrl = "";
    let lastTitle = "";

    btn.addEventListener("click", async () => {
      clearError(root);
      savedBox.style.display = "none";
      savedBox.textContent = "";
      result.style.display = "none";
      saveBtn.style.display = "none";

      const title = (input.value || "").trim();
      if (!title) {
        showError(root, "Please enter a title or keyword");
        return;
      }

      btn.disabled = true;
      btn.textContent = "Generating...";

      try {
        const data = await postAjax("aig_generate", {
          nonce: AIG.nonce,
          title,
        });

        lastUrl = data.url || "";
        lastTitle = title;

        if (!lastUrl) throw new Error("No image URL returned");

        img.src = lastUrl;
        download.href = lastUrl;

        result.style.display = "block";

        if (AIG.canSave) {
          saveBtn.style.display = "inline-flex";
          saveBtn.disabled = false;
          saveBtn.textContent = "Save to Media Library";
        }
      } catch (e) {
        showError(root, e.message || "Failed to generate image");
      } finally {
        btn.disabled = false;
        btn.textContent = "Generate Image";
      }
    });

    saveBtn.addEventListener("click", async () => {
      if (!lastUrl) return;

      clearError(root);
      savedBox.style.display = "none";
      savedBox.textContent = "";

      saveBtn.disabled = true;
      saveBtn.textContent = "Saving...";

      try {
        const data = await postAjax("aig_save_media", {
          nonce: AIG.nonce,
          image_url: lastUrl,
          title: lastTitle,
        });

        savedBox.style.display = "block";
        savedBox.innerHTML = `Saved to Media Library ✅ <a href="${data.url}" target="_blank" rel="noreferrer">View</a>`;

        saveBtn.textContent = "Saved ✓";
        setTimeout(() => {
          saveBtn.textContent = "Save to Media Library";
          saveBtn.disabled = false;
        }, 1200);
      } catch (e) {
        showError(root, e.message || "Failed to save to media");
        saveBtn.textContent = "Save to Media Library";
        saveBtn.disabled = false;
      }
    });
  }

  function initAll() {
    document.querySelectorAll(".aig-box").forEach(init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();
