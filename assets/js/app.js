import { initializeApp } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-app.js";
import { getAuth, signInAnonymously, onAuthStateChanged } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-auth.js";
import { getFirestore, collection, onSnapshot, addDoc, setDoc, doc } from "https://www.gstatic.com/firebasejs/11.6.1/firebase-firestore.js";

const firebaseConfig = { /* ... */ };
const app = initializeApp(firebaseConfig);
const db = getFirestore(app);
const auth = getAuth(app);
let userId;

onAuthStateChanged(auth, user => {
    if(user){
        userId = user.uid;
        setupListeners();
    }
});

async function initAuth() { await signInAnonymously(auth); }
document.addEventListener('DOMContentLoaded', () => initAuth());

function setupListeners(){
    const leadsCollectionRef = collection(db, `users/${userId}/leads`);
    onSnapshot(leadsCollectionRef, snapshot => {
        const leads = snapshot.docs.map(d => ({ id: d.id, ...d.data() }));
        renderLeads(leads);
    });
}

function renderLeads(leads){
    const tbody = document.getElementById('leads-table-body');
    tbody.innerHTML = leads.map(l => `
        <tr class="border-b hover:bg-gray-50">
            <td class="py-3 px-2 font-medium">${l.name}</td>
            <td class="py-3 px-2">${l.email || ''} ${l.phone || ''}</td>
            <td class="py-3 px-2">${l.source}</td>
            <td class="py-3 px-2">${l.status}</td>
            <td class="py-3 px-2">Editar / Excluir</td>
        </tr>
    `).join('');
}
