import { useNavigate, useParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";

import { AdminNav } from "../components/AdminNav";
import { DubClipReviewEditor } from "../components/dub/DubClipReviewEditor";
import { Button } from "../components/ui/Button";

export function AdminDubClipEditorPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const clipId = Number(id);

  return (
    <div className="admin-page">
      <AdminNav />
      <Button variant="ghost" onClick={() => navigate("/admin/dub-clips")}>
        <ArrowLeft size={15} strokeWidth={2.25} /> Back to clips
      </Button>
      {Number.isFinite(clipId) ? (
        <DubClipReviewEditor
          clipId={clipId}
          endpointBase="/api/admin/dub-clips"
          onPublished={() => navigate("/admin/dub-clips")}
        />
      ) : (
        <p className="form-error">Bad clip id.</p>
      )}
    </div>
  );
}
