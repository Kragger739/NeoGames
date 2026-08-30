import { Link } from "react-router-dom";

export function NotFoundPage() {
  return (
    <div className="auth-page">
      <h1>Page not found</h1>
      <p className="hint">That link doesn&rsquo;t go anywhere.</p>
      <p>
        <Link to="/">← Back to home</Link>
      </p>
    </div>
  );
}
