// JS para integração de equipes: CRUD de tarefas + feed de atividades
export async function fetchTasks(filters={}) {
  const params = new URLSearchParams({action:'list', ...filters});
  const res = await fetch('includes/team_tasks_api.php?' + params);
  return await res.json();
}

export async function addTask(data) {
  const res = await fetch('includes/team_tasks_api.php?action=add', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });
  return await res.json();
}

export async function updateTask(id, data) {
  const res = await fetch('includes/team_tasks_api.php?action=update&id=' + id, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(data)
  });
  return await res.json();
}

export async function fetchRecentActivities() {
  const res = await fetch('includes/team_tasks_api.php?action=recent_activities');
  return await res.json();
}

export async function deleteTask(id) {
  const res = await fetch('includes/team_tasks_api.php?action=delete&id=' + id, {method:'POST'});
  return await res.json();
}
